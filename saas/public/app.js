document.addEventListener('submit', async event => {
  const form = event.target;
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
  if (!form.hasAttribute('data-inference')) return;
  event.preventDefault();
  const button = form.querySelector('button');
  const status = form.querySelector('[data-inference-status]');
  const output = form.parentElement.querySelector('[data-inference-result]');
  button.disabled = true; output.hidden = true; status.textContent = 'Reserving credits…';
  const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content };
  let requestId = null;
  try {
    let response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers });
    let data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Request failed.');
    requestId = data.id;
    for (let attempt = 0; attempt < 80 && ['reserved', 'dispatched'].includes(data.state); attempt++) {
      status.textContent = data.state === 'reserved' ? 'Queued. Waiting for the AI worker…' : 'Generating reply…';
      await new Promise(resolve => setTimeout(resolve, 1500));
      response = await fetch('/inference/' + encodeURIComponent(requestId), { headers: { Accept: 'application/json' } });
      data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not read request status.');
    }
    if (data.state === 'settled') {
      output.textContent = data.result?.content || 'Request completed.'; output.hidden = false;
      status.textContent = 'Complete · ' + (data.charged_credits / data.credit_scale).toFixed(6) + ' credits';
      form.elements.idempotency_key.value = crypto.randomUUID();
    } else if (data.state === 'released') {
      status.textContent = 'Request stopped: ' + (data.error || 'provider rejected request') + '. Credits released.';
      form.elements.idempotency_key.value = crypto.randomUUID();
    } else status.textContent = 'Request ' + requestId + ': ' + data.state + '. Its credit hold is preserved until the outcome is known. Check usage or contact HostingSpell.';
  } catch (error) { status.textContent = error.message + (requestId ? ' Reference: ' + requestId : ' Retrying this form keeps the same request key.'); }
  finally { button.disabled = false; }
});

document.addEventListener('click', event => { if (event.target.closest('a[href="#new-brand"]')) { const panel = document.querySelector('#new-brand'); if (panel) panel.open = true; } });
