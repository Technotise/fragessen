# FragEssen

**Semantische Suche in kommunalpolitischen Sitzungsprotokollen** — ein
Retrieval-Augmented-Generation-System (RAG), das Niederschriften kommunaler
Gremien per natürlichsprachlicher Frage durchsuchbar macht.

Live-Instanz: [fragessen.stadtstimme.de](https://fragessen.stadtstimme.de) —
Protokolle der Stadt Essen (Bezirksvertretung IV, Ausschuss für Recht, Sicherheit
und Ordnung u. a.), Korpus 2004–2026.

Entstanden als Masterarbeit im Verbundstudiengang Angewandte Künstliche
Intelligenz (Fachhochschule Südwestfalen): *„Entwicklung und Evaluation eines
Retrieval-Augmented-Generation-Systems zur semantischen Erschließung
kommunalpolitischer Dokumente"*.

## Motivation

Ratsinformationssysteme machen Sitzungsunterlagen zwar zugänglich, aber nicht
*erschließbar*: Wie sich ein Thema über Jahre und Sitzungen hinweg entwickelt
hat, lässt sich nur durch manuelles Durcharbeiten vieler PDFs rekonstruieren —
eine Hürde besonders für neue Gremienmitglieder, sachkundige Bürger:innen und
interessierte Öffentlichkeit. FragEssen setzt hier an: Fragen in Alltagssprache,
Antworten mit Quellenangabe auf Ebene einzelner Tagesordnungspunkte.

## Architektur

Drei getrennt deployte Systeme:

| Ordner | System | Funktion | Stack / Host |
|---|---|---|---|
| [`System_1/`](System_1/) | Ingest | PDF-Upload, strukturierte Extraktion (Mistral Document AI), manuelle Review/Kuratierung, Export | PHP + MySQL, Shared Webhosting |
| [`System_2/`](System_2/) | RAG-Backend | Segmentierung, Chunking, Embeddings, Multi-Layer Retrieval, Antwortgenerierung (FastAPI) | Python + PostgreSQL/pgvector, VPS |
| [`System_3/`](System_3/) | Frontend | Öffentliches Chat-Interface, Zugangscodes, Rate-Limiting, Admin-Panel, Evaluations-Umfrage | PHP + MySQL, Shared Webhosting |

```
                 SFTP (Pakete:                    HTTP + X-API-Key
                 PDF + kuratierte JSONs)
┌────────────┐   ─────────────────►  ┌────────────┐  ◄─────────────  ┌────────────┐
│  System 1  │                       │  System 2  │                  │  System 3  │
│   Ingest   │                       │RAG-Backend │  ─────────────►  │  Frontend  │
└─────┬──────┘                       └─────┬──────┘   Antwort/Stream └─────┬──────┘
      │                                    │                               │
      └────────── MySQL (geteilt) ─────────┼───────────────────────────────┘
                                     PostgreSQL
                                  (raw / rag / ops)
```

System 1 und 3 teilen sich eine MySQL-Datenbank
([`db/schema.mysql.sql`](db/schema.mysql.sql)); System 2 hält den kompletten
RAG-Datenbestand in PostgreSQL ([`System_2/schema.postgres.sql`](System_2/schema.postgres.sql)).

## Technische Eckpunkte

- **Modellstack ausschließlich Mistral AI** — bewusste Entscheidung für einen
  europäischen Anbieter aus Datenschutz- und Souveränitätsgründen:
  `mistral-ocr-latest` (Dokumentenextraktion), `mistral-embed` (Embeddings),
  `mistral-medium-latest` (Kondensierung von Folgefragen) sowie
  `mistral-small-latest`, `mistral-medium-latest` und `mistral-large-latest`
  für die Antwortgenerierung, wählbar über den `quality`-Parameter
  (Standard: `mistral-small-latest`).
- **Multi-Layer Retrieval:** Dense (pgvector/HNSW), Sparse (tsvector, german)
  und Trigram (pg_trgm) über zwei Granularitäten (Chunks und Segmente) plus
  strukturierte Metadaten, fusioniert per Reciprocal Rank Fusion;
  Gremien-Disambiguierung über durchgängigen `gremium_key`.
- **Deterministische, agenda-getriebene Segmentierung** der Protokolle in
  Tagesordnungspunkte (Marker-Regex, Layout-Heuristiken, DP-Pfadwahl) mit
  Document-AI-Fallback — Quellenangaben zeigen dadurch auf einzelne TOPs
  statt ganzer Dokumente.
- **Human-in-the-Loop:** Extraktionsergebnisse (Stammdaten, Anwesenheit,
  Tagesordnung) werden vor der Indexierung in System 1 manuell reviewt.
- **Reproduzierbarkeit:** Temperature 0.0 für Condensation und
  Antwortgenerierung; Synonym-Expansion mit gecachtem LLM-Fallback und
  geschützten manuellen Einträgen.

## Setup

Jedes System bringt eine eigene Setup-Anleitung mit — in dieser Reihenfolge
aufsetzen:

1. **Datenbanken:** MySQL-Schema ([`db/schema.mysql.sql`](db/schema.mysql.sql))
   und PostgreSQL-Schema ([`System_2/schema.postgres.sql`](System_2/schema.postgres.sql))
2. **[System 2](System_2/README.md)** — Backend zuerst (API-Key erzeugen)
3. **[System 1](System_1/README.md)** — Ingest, SFTP-Schlüsselpaar gegen System 2
4. **[System 3](System_3/README.md)** — Frontend, `api_key` = System-2-Key

Benötigt wird ein Mistral-API-Key ([console.mistral.ai](https://console.mistral.ai)).
Die Sitzungsprotokolle selbst sind **nicht** Teil dieses Repositories — sie
werden über die eigene Kommune bzw. deren Ratsinformationssystem beschafft und
über System 1 eingespielt.

## Kontext & Zitation

Das System wurde mit Mitgliedern der Bezirksvertretung IV der Stadt Essen und
weiteren Stakeholdern erprobt (explorative Nutzerevaluation, dokumentiert in der
Masterarbeit). Wer das Projekt in wissenschaftlichen Arbeiten referenziert,
zitiert die Masterarbeit; Details auf Anfrage.

## Lizenz

[EUPL-1.2](LICENSE) — European Union Public Licence. Verbindliche Fassungen in
allen EU-Amtssprachen:
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12

Die Ubuntu-Fontfamilie in `System_3/httpdocs/assets/fonts/ubuntu/` steht unter
der Ubuntu Font Licence 1.0 (Lizenztext liegt bei).
