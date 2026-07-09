<?php
// /umfrage/_footer.php
// Wird in allen Umfrage-Seiten kurz vor </body> eingebunden.
// Liefert Footer-Link + Impressum-Modal aus pages/impressum.html.
?>
<div class="survey-footer-links">
  <a href="#" id="link-survey-impressum">Impressum</a>
</div>

<div class="page-modal" id="modal-survey-impressum" hidden>
  <div class="page-modal-card">
    <?php include __DIR__ . '/../pages/impressum.html'; ?>
    <button class="page-modal-close" onclick="this.closest('.page-modal').hidden=true">Schließen</button>
  </div>
</div>

<style>
.survey-footer-links {
  text-align: center;
  padding: 1rem 0 .4rem;
  font-size: .82rem;
  opacity: .55;
}
.survey-footer-links a {
  color: inherit;
  text-decoration: none;
}
.survey-footer-links a:hover {
  opacity: .9;
  text-decoration: underline;
}

.page-modal {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,.5);
}
.page-modal[hidden] { display: none; }
.page-modal-card {
  background: var(--surface);
  border-radius: var(--radius);
  padding: 1.6rem;
  max-width: 600px;
  max-height: 85vh;
  width: calc(100% - 2rem);
  overflow-y: auto;
  box-shadow: 0 8px 28px rgba(0,0,0,.18);
}
.page-modal-card h2 { margin-top: 0; }
.page-modal-close {
  margin-top: 1rem;
  background: var(--accent);
  color: #fff;
  border: none;
  padding: .6rem 1.2rem;
  border-radius: var(--radius);
  cursor: pointer;
  font-family: inherit;
  font-size: .95rem;
}
.page-modal-close:hover { background: var(--accent-hover); }
</style>

<script>
document.getElementById('link-survey-impressum')?.addEventListener('click', e => {
  e.preventDefault();
  document.getElementById('modal-survey-impressum').hidden = false;
});
</script>
