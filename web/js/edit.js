const form = document.getElementById('editForm');
const statusEl = document.getElementById('editStatus');
const previewName = document.getElementById('previewName');
const saveButton = document.getElementById('saveBtn');

function setStatus(text, state) {
  if (!statusEl) return;
  statusEl.textContent = text;
  statusEl.dataset.state = state || '';
}

function currentPayload() {
  return {
    character: form.elements.character.value.trim(),
    title: form.elements.title.value.trim(),
    name: form.elements.name.value.trim(),
    tags: form.elements.tags.value.trim(),
  };
}

function refreshPreview(work) {
  if (!previewName) return;
  previewName.textContent = work.character || work.title || work.name || work.id || form?.dataset.id || '';
}

form?.querySelectorAll('[name="character"], [name="title"], [name="name"]').forEach((input) => {
  input.addEventListener('input', () => refreshPreview(currentPayload()));
});

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const id = form.dataset.id;
  setStatus('保存中…', 'running');
  saveButton.disabled = true;
  form.setAttribute('aria-busy', 'true');

  try {
    const res = await fetch('/api/works/' + encodeURIComponent(id), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(currentPayload()),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) {
      throw new Error(json.error || ('HTTP ' + res.status));
    }

    const work = json.data;
    form.elements.character.value = work.character || '';
    form.elements.title.value = work.title || '';
    form.elements.name.value = work.name || '';
    form.elements.tags.value = (work.tags || []).join(', ');
    refreshPreview(work);
    setStatus('已保存', 'success');
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    setStatus(`保存失败：${message}。请检查内容或网络连接后重试。`, 'error');
  } finally {
    saveButton.disabled = false;
    form.removeAttribute('aria-busy');
  }
});
