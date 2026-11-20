(() => {
  'use strict';

  for (const panel of document.querySelectorAll('[data-sd-copilot]')) {
    if (panel.dataset.bound) continue;
    panel.dataset.bound = 'true';

    const searchForm = panel.querySelector('[data-sd-search]');
    const recordsForm = panel.querySelector('[data-sd-records]');
    const status = panel.querySelector('[data-sd-status]');
    const cardRoot = panel.querySelector('[data-sd-card]');
    let busy = false;

    const request = async data => {
      const response = await fetch(panel.dataset.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...data, csrf: panel.dataset.csrf})
      });
      if (!response.headers.get('Content-Type')?.includes('application/json')) {
        throw new Error('The connector did not return a result. Refresh your WHMCS admin session and try again.');
      }
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'The lookup could not be completed.');
      return result;
    };

    const run = async (message, work) => {
      if (busy) return;
      busy = true;
      panel.setAttribute('aria-busy', 'true');
      const buttons = [...panel.querySelectorAll('button:not(:disabled)')];
      const selects = [...recordsForm.querySelectorAll('select:not(:disabled)')];
      const controls = [...buttons, ...selects];
      controls.forEach(control => { control.disabled = true; });
      status.textContent = message;
      try {
        await work();
      } catch (error) {
        status.textContent = error.message || 'Unable to load records.';
      } finally {
        busy = false;
        controls.forEach(control => { control.disabled = false; });
        panel.removeAttribute('aria-busy');
      }
    };

    const recordLink = item => {
      const record = item.record;
      if (!record || !Number.isSafeInteger(record.id) || record.id <= 0) {
        return document.createTextNode(item.text);
      }
      const id = String(record.id);
      const urls = {
        client: ['clientssummary.php', {userid: id}],
        invoice: ['invoices.php', {action: 'view', id}],
        product: ['configproducts.php', {action: 'edit', id}]
      };
      if (record.type === 'service' && Number.isSafeInteger(record.client_id) && record.client_id > 0) {
        urls.service = ['clientsservices.php', {userid: String(record.client_id), id}];
      }
      if (!Object.prototype.hasOwnProperty.call(urls, record.type)) return document.createTextNode(item.text);
      const [script, params] = urls[record.type];
      const url = new URL(script, window.location.href);
      url.search = new URLSearchParams(params).toString();
      const link = document.createElement('a');
      link.href = url.href;
      link.textContent = item.text;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      return link;
    };

    const renderCard = (card, selection) => {
      const heading = document.createElement('h4');
      heading.append(card.title + ' for ', recordLink(card.client));
      cardRoot.replaceChildren(heading);
      if (card.rows.length) {
        const table = document.createElement('table');
        const head = table.createTHead().insertRow();
        card.columns.forEach(column => {
          const cell = document.createElement('th');
          cell.scope = 'col';
          cell.textContent = column;
          head.append(cell);
        });
        const rows = table.createTBody();
        card.rows.forEach(row => {
          const tr = rows.insertRow();
          row.forEach(item => tr.insertCell().append(recordLink(item)));
        });
        cardRoot.append(table);
      }
      const pager = document.createElement('div');
      pager.className = 'sd-copilot-pager';
      for (const [label, page, available] of [
        ['Previous page', card.page - 1, card.page > 1],
        ['Next page', card.page + 1, card.has_more]
      ]) {
        if (!available) continue;
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', () => loadRecords(selection, page));
        pager.append(button);
      }
      cardRoot.append(pager);
      status.textContent = card.rows.length
        ? `Page ${card.page} · ${card.rows.length} records shown`
        : 'No records on this page.';
    };

    const loadRecords = (selection, page) => run('Loading records…', async () => {
      cardRoot.replaceChildren();
      const response = await request({action: 'copilot_records', ...selection, page});
      renderCard(response, selection);
    });

    searchForm.addEventListener('submit', event => {
      event.preventDefault();
      run('Finding clients…', async () => {
        cardRoot.replaceChildren();
        recordsForm.hidden = true;
        const response = await request({action: 'copilot_search', search: searchForm.elements.search.value});
        const select = recordsForm.elements.client;
        select.replaceChildren(new Option('Choose a client', ''));
        response.clients.forEach(client => {
          const details = [client.company, client.status].filter(Boolean).join(' · ');
          select.append(new Option(`${client.name} (#${client.id})${details ? ' · ' + details : ''}`, client.id));
        });
        recordsForm.hidden = response.clients.length === 0;
        if (response.clients.length === 1 && !response.has_more) select.selectedIndex = 1;
        status.textContent = response.has_more
          ? 'More matches exist. Use a client ID to find a specific account.'
          : response.clients.length ? 'Choose the client and the records to view.' : 'No matching client. Try the client ID.';
      });
    });

    recordsForm.addEventListener('submit', event => {
      event.preventDefault();
      loadRecords({client_id: Number(recordsForm.elements.client.value), resource: recordsForm.elements.resource.value}, 1);
    });
    recordsForm.addEventListener('change', () => {
      cardRoot.replaceChildren();
      if (!busy) status.textContent = 'Select Show records to load this selection.';
    });
  }
})();
