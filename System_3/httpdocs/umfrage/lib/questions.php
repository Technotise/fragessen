<?php
// /umfrage/lib/questions.php
// Zentrale Fragendefinition. Einmal hier ändern, überall konsistent.

declare(strict_types=1);

/**
 * Glossar: Begriffe, die in Labels mit Hover/Klick-Tooltip versehen werden.
 * Schlüssel = genau der im Label verwendete Begriff (exakter String-Match).
 */
const GLOSSAR = [
    'RAG' => 'Retrieval-Augmented Generation: Ein Sprachmodell kombiniert seine Antwort mit gezielt herausgesuchten Textstellen aus einer Dokumentensammlung.',
    'RIS' => 'Ratsinformationssystem: die offizielle digitale Dokumentenablage der Stadt Essen mit Sitzungsunterlagen und Protokollen.',
    'Ratsinformationssystem (RIS)' => 'Ratsinformationssystem: die offizielle digitale Dokumentenablage der Stadt Essen mit Sitzungsunterlagen und Protokollen.',
    'KI-Sprachmodell' => 'Ein statistisches Modell, das natürliche Sprache verarbeitet und erzeugen kann – hier eingesetzt, um Antworten aus den Protokoll-Texten zusammenzufassen.',
];

/**
 * Wandelt einen Frage-Label-String in HTML um und ersetzt bekannte
 * Glossar-Begriffe durch <abbr class="glossar" data-tip="...">…</abbr>.
 */
function renderLabelWithGlossar(string $label): string
{
    $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    foreach (GLOSSAR as $term => $tip) {
        // Ganze Wörter bevorzugen; einfacher String-Replace reicht hier,
        // weil die Begriffe distinkt genug sind.
        $pattern = '/\b' . preg_quote($term, '/') . '\b/u';
        $replacement = '<abbr class="glossar" data-tip="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . '</abbr>';
        // Nur den ersten Treffer ersetzen, sonst wird's unübersichtlich
        $safe = preg_replace($pattern, $replacement, $safe, 1);
    }
    return $safe;
}

const LIKERT_5 = [
    1 => 'Stimme gar nicht zu',
    2 => 'Stimme eher nicht zu',
    3 => 'Teils / teils',
    4 => 'Stimme eher zu',
    5 => 'Stimme voll zu',
];

const LIKERT_7 = [
    1 => 'Stimme überhaupt nicht zu',
    2 => '',
    3 => '',
    4 => 'Neutral',
    5 => '',
    6 => '',
    7 => 'Stimme voll zu',
];

function stage1Questions(): array
{
    return [
        [
            'key'     => 'usefulness_speed',
            'type'    => 'likert5',
            'label'   => 'FragEssen hilft mir, mich schneller über kommunalpolitische Themen zu informieren.',
            'options' => LIKERT_5,
        ],
        [
            'key'          => 'usefulness_workflow',
            'type'         => 'likert5',
            'label'        => 'FragEssen passt zu meinem Arbeitsalltag mit kommunalen Dokumenten.',
            'options'      => LIKERT_5,
            'hide_for_role' => ['buergerschaft'],
        ],
        [
            'key'         => 'ris_time_compare',
            'type'        => 'likert5',
            'label'       => 'Mit FragEssen erledige ich eine typische Recherche schneller als mit dem Ratsinformationssystem (RIS).',
            'options'     => LIKERT_5,
            'conditional' => 'ris_known',
        ],
        [
            'key'         => 'ris_preference',
            'type'        => 'likert5',
            'label'       => 'Wenn ich nachvollziehen will, wie sich ein Thema über mehrere Sitzungen entwickelt hat, ziehe ich FragEssen dem RIS vor.',
            'options'     => LIKERT_5,
            'conditional' => 'ris_known',
        ],
        [
            'key'   => 'umux_lite_intro',
            'type'  => 'info',
            'label' => 'Die folgenden zwei Fragen verwenden eine 7-stufige Skala (statt 5 wie zuvor). Bitte kurz beachten.',
        ],
        [
            'key'     => 'umux_lite_capability',
            'type'    => 'likert7',
            'label'   => 'FragEssen erfüllt meine Anforderungen.',
            'options' => LIKERT_7,
        ],
        [
            'key'     => 'umux_lite_easeofuse',
            'type'    => 'likert7',
            'label'   => 'FragEssen ist einfach zu bedienen.',
            'options' => LIKERT_7,
        ],
        [
            'key'   => 'nps',
            'type'  => 'nps',
            'label' => 'Wie wahrscheinlich ist es, dass Sie FragEssen weiterempfehlen? (0 = unwahrscheinlich, 10 = sehr wahrscheinlich)',
        ],
    ];
}

function stage2Questions(): array
{
    return [
        [
            'key'     => 'trust_sources',
            'type'    => 'likert5',
            'label'   => 'Die angegebenen Quellen helfen mir, die Antwort nachzuvollziehen.',
            'options' => LIKERT_5,
        ],
        [
            'key'     => 'trust_overall',
            'type'    => 'likert5',
            'label'   => 'Ich vertraue den Antworten von FragEssen.',
            'options' => LIKERT_5,
        ],
        [
            'key'     => 'trust_hallucination_awareness',
            'type'    => 'likert5',
            'label'   => 'Ich erkenne, wenn FragEssen unsicher ist oder eine Frage nicht beantworten kann.',
            'options' => LIKERT_5,
        ],
        [
            'key'   => 'text_surprise',
            'type'  => 'textarea',
            'label' => 'Was hat Sie bei FragEssen positiv überrascht oder enttäuscht?',
        ],
        [
            'key'   => 'text_usecase',
            'type'  => 'textarea',
            'label' => 'Für welche konkreten Aufgaben würden Sie FragEssen im Alltag nutzen?',
        ],
        [
            'key'   => 'text_missing',
            'type'  => 'textarea',
            'label' => 'Was fehlt Ihnen an FragEssen? Was würden Sie verändern?',
        ],
        [
            'key' => 'sus_intro',
            'type' => 'info',
            'label' => 'Die folgenden 10 Fragen (System Usability Scale) sind optional, geben aber einen standardisierten Usability-Score. Sie benötigen ca. 2 Minuten.',
        ],
        [ 'key' => 'sus_1',  'type' => 'likert5', 'label' => 'Ich denke, dass ich FragEssen gerne häufig benutzen würde.', 'options' => LIKERT_5, 'sus_item' => 1 ],
        [ 'key' => 'sus_2',  'type' => 'likert5', 'label' => 'Ich finde FragEssen unnötig komplex.',                       'options' => LIKERT_5, 'sus_item' => 2 ],
        [ 'key' => 'sus_3',  'type' => 'likert5', 'label' => 'Ich finde FragEssen einfach zu bedienen.',                    'options' => LIKERT_5, 'sus_item' => 3 ],
        [ 'key' => 'sus_4',  'type' => 'likert5', 'label' => 'Ich glaube, ich würde Unterstützung brauchen, um FragEssen zu nutzen.', 'options' => LIKERT_5, 'sus_item' => 4 ],
        [ 'key' => 'sus_5',  'type' => 'likert5', 'label' => 'Ich finde, dass die verschiedenen Funktionen von FragEssen gut integriert sind.', 'options' => LIKERT_5, 'sus_item' => 5 ],
        [ 'key' => 'sus_6',  'type' => 'likert5', 'label' => 'Ich finde, dass es in FragEssen zu viele Inkonsistenzen gibt.', 'options' => LIKERT_5, 'sus_item' => 6 ],
        [ 'key' => 'sus_7',  'type' => 'likert5', 'label' => 'Ich kann mir vorstellen, dass die meisten Menschen FragEssen schnell lernen würden.', 'options' => LIKERT_5, 'sus_item' => 7 ],
        [ 'key' => 'sus_8',  'type' => 'likert5', 'label' => 'Ich finde FragEssen sehr umständlich zu bedienen.',            'options' => LIKERT_5, 'sus_item' => 8 ],
        [ 'key' => 'sus_9',  'type' => 'likert5', 'label' => 'Ich fühle mich bei der Benutzung von FragEssen sehr sicher.',  'options' => LIKERT_5, 'sus_item' => 9 ],
        [ 'key' => 'sus_10', 'type' => 'likert5', 'label' => 'Ich müsste viel lernen, bevor ich mit FragEssen arbeiten kann.', 'options' => LIKERT_5, 'sus_item' => 10 ],
    ];
}
