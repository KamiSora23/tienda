// Confirmación propia de la app. window.confirm() no siempre se muestra (algunos navegadores
// integrados o con diálogos bloqueados la responden al instante como "Cancelar"), y entonces
// la acción parece no hacer nada.
type Options = { confirmLabel?: string; cancelLabel?: string; danger?: boolean };

export function askConfirm(message: string, { confirmLabel = "Confirmar", cancelLabel = "Cancelar", danger = false }: Options = {}): Promise<boolean> {
  return new Promise((resolve) => {
    // Dentro de .app-shell hereda los colores de la empresa.
    const host = document.querySelector(".app-shell") ?? document.body;
    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop confirm-backdrop";
    backdrop.innerHTML = `<section class="modal-card confirm-card" role="alertdialog" aria-modal="true"><p></p><footer><button type="button" data-answer="no"></button><button type="button" data-answer="yes" class="${danger ? "danger-button" : "primary"}"></button></footer></section>`;
    backdrop.querySelector("p")!.textContent = message;
    backdrop.querySelector('[data-answer="no"]')!.textContent = cancelLabel;
    const yes = backdrop.querySelector<HTMLButtonElement>('[data-answer="yes"]')!;
    yes.textContent = confirmLabel;

    const close = (answer: boolean) => { document.removeEventListener("keydown", onKey, true); backdrop.remove(); resolve(answer); };
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") { e.stopPropagation(); close(false); } };
    backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) close(false); });
    backdrop.addEventListener("click", (e) => { const answer = (e.target as HTMLElement).dataset.answer; if (answer) close(answer === "yes"); });
    document.addEventListener("keydown", onKey, true);
    host.appendChild(backdrop);
    yes.focus();
  });
}
