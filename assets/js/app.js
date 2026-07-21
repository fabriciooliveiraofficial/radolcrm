(() => {
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];

  qsa('[data-menu]').forEach((button) => button.addEventListener('click', () => {
    document.body.classList.toggle('menu-open');
  }));

  qsa('[data-toast]').forEach((toast) => {
    let timer;
    const close = () => {
      clearTimeout(timer);
      toast.classList.add('leaving');
      setTimeout(() => toast.remove(), 220);
    };
    const schedule = () => { timer = setTimeout(close, toast.classList.contains('danger') ? 8000 : 5500); };
    toast.querySelector('button')?.addEventListener('click', close);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', schedule);
    schedule();
  });

  const showConfirmation = (message, destructive = false) => new Promise((resolve) => {
    const previousFocus = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `<section class="confirm-popup" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message">
      <button class="confirm-close" type="button" aria-label="Fechar">×</button>
      <span class="confirm-icon ${destructive ? 'danger' : ''}">${destructive ? '!' : '?'}</span>
      <div><p class="eyebrow">CONFIRMAÇÃO</p><h2 id="confirm-title">Confirmar ação</h2><p id="confirm-message"></p></div>
      <footer><button class="button ghost confirm-cancel" type="button">Cancelar</button><button class="button ${destructive ? 'danger-button' : 'primary'} confirm-accept" type="button">Confirmar</button></footer>
    </section>`;
    qs('#confirm-message', overlay).textContent = message;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('open'));

    const accept = qs('.confirm-accept', overlay);
    let settled = false;
    const finish = (result) => {
      if (settled) return;
      settled = true;
      overlay.classList.remove('open');
      overlay.classList.add('closing');
      setTimeout(() => overlay.remove(), 180);
      document.removeEventListener('keydown', onKeydown);
      previousFocus?.focus?.();
      resolve(result);
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') finish(false);
    };
    qs('.confirm-cancel', overlay).addEventListener('click', () => finish(false));
    qs('.confirm-close', overlay).addEventListener('click', () => finish(false));
    accept.addEventListener('click', () => finish(true));
    overlay.addEventListener('click', (event) => { if (event.target === overlay) finish(false); });
    document.addEventListener('keydown', onKeydown);
    setTimeout(() => accept.focus(), 30);
  });

  qsa('form[data-confirm]').forEach((form) => form.addEventListener('submit', async (event) => {
    if (form.dataset.confirmed === '1') {
      delete form.dataset.confirmed;
      return;
    }
    event.preventDefault();
    const submitter = event.submitter;
    const action = qs('input[name="action"]', form)?.value || '';
    const destructive = form.classList.contains('danger-zone') || action.startsWith('delete_');
    if (await showConfirmation(form.dataset.confirm, destructive)) {
      form.dataset.confirmed = '1';
      submitter ? form.requestSubmit(submitter) : form.requestSubmit();
    }
  }));

  const paymentChecks = qsa('[data-payment-check]');
  const checkAll = qs('[data-check-all]');
  const bulkSubmit = qs('[data-bulk-submit]');
  const updateBulkState = () => {
    const selected = paymentChecks.filter((checkbox) => checkbox.checked).length;
    if (bulkSubmit) {
      bulkSubmit.disabled = selected === 0;
      bulkSubmit.textContent = selected > 0 ? `✓ Confirmar selecionados (${selected})` : '✓ Confirmar selecionados';
    }
    if (checkAll) {
      checkAll.checked = paymentChecks.length > 0 && selected === paymentChecks.length;
      checkAll.indeterminate = selected > 0 && selected < paymentChecks.length;
    }
  };
  checkAll?.addEventListener('change', () => {
    paymentChecks.forEach((checkbox) => { checkbox.checked = checkAll.checked; });
    updateBulkState();
  });
  paymentChecks.forEach((checkbox) => checkbox.addEventListener('change', updateBulkState));
  updateBulkState();

  const passwordButton = qs('[data-toggle-password]');
  if (passwordButton) passwordButton.addEventListener('click', () => {
    const input = qs('#password');
    input.type = input.type === 'password' ? 'text' : 'password';
    passwordButton.textContent = input.type === 'password' ? 'Ver' : 'Ocultar';
  });

  qsa('[data-country]').forEach((country) => country.addEventListener('change', () => {
    const currency = qs('[data-currency]', country.form);
    if (currency) currency.value = country.value === 'US' ? 'USD' : 'BRL';
  }));

  const formatBrl = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);
  qsa('[data-money-form]').forEach((form) => {
    const currency = qs('[data-money-currency]', form);
    const amount = qs('[data-money-amount]', form);
    const fee = qs('[data-money-fee]', form);
    const rate = qs('[data-money-rate]', form);
    const preview = qs('[data-money-preview]', form);
    const currentRate = Number(form.dataset.currentRate || 1);
    const dailyRate = form.dataset.dailyRate === '1';
    const rateDate = qs('[data-rate-date]', form);
    const rateSource = qs('[data-rate-source]', form);
    const rateHelp = qs('[data-rate-help]', form);
    let previousCurrency = currency.value;

    const calculate = () => {
      const multiplier = currency.value === 'USD' ? Number(rate.value || (dailyRate ? 0 : currentRate)) : 1;
      const net = Math.max(0, Number(amount.value || 0) - Number(fee?.value || 0));
      preview.textContent = formatBrl(net * multiplier);
      rate.readOnly = currency.value === 'BRL';
      rate.closest('label')?.classList.toggle('field-disabled', currency.value === 'BRL');
      if (currency.value === 'BRL') {
        rate.value = '1';
        if (rateSource) rateSource.value = 'BRL';
      } else if (!dailyRate && Number(rate.value) === 1) {
        rate.value = currentRate;
      }
    };

    const fetchDailyRate = async () => {
      if (!dailyRate || currency.value !== 'USD' || !rateDate?.value) return;
      if (rateHelp) rateHelp.textContent = 'Consultando cotação diária…';
      try {
        const response = await fetch(`index.php?page=exchange-rate&date=${encodeURIComponent(rateDate.value)}`, {
          headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Cotação indisponível');
        rate.value = Number(data.rate.bid).toFixed(6);
        rate.dataset.fetchedRate = rate.value;
        if (rateSource) rateSource.value = data.rate.source;
        if (rateHelp) rateHelp.textContent = `${data.rate.source} · ${rateDate.value.split('-').reverse().join('/')}`;
        calculate();
      } catch (error) {
        if (rateHelp) rateHelp.textContent = `${error.message}. Você pode informar a taxa manualmente.`;
      }
    };

    [amount, fee].filter(Boolean).forEach((input) => input.addEventListener('input', calculate));
    currency.addEventListener('input', () => {
      if (dailyRate && currency.value === 'USD' && previousCurrency === 'BRL') {
        rate.value = '';
        if (rateSource) rateSource.value = '';
      }
      previousCurrency = currency.value;
      calculate();
      fetchDailyRate();
    });
    rate.addEventListener('input', () => {
      calculate();
      if (rateSource && rate.value !== rate.dataset.fetchedRate && currency.value === 'USD') rateSource.value = 'Manual';
    });
    rateDate?.addEventListener('change', fetchDailyRate);
    calculate();
    if (dailyRate && form.dataset.newRecord === '1' && currency.value === 'USD') fetchDailyRate();
  });

  const subForm = qs('[data-subscription-form]');
  if (subForm) {
    const client = qs('[data-sub-client]', subForm);
    const product = qs('[data-sub-product]', subForm);
    const currency = qs('[data-sub-currency]', subForm);
    const price = qs('[data-sub-price]', subForm);
    const setPrice = () => {
      const selected = product.selectedOptions[0];
      if (selected?.value) price.value = currency.value === 'USD' ? selected.dataset.usd : selected.dataset.brl;
    };
    client.addEventListener('change', () => {
      const selected = client.selectedOptions[0];
      if (selected?.dataset.currency) currency.value = selected.dataset.currency;
      setPrice();
    });
    product.addEventListener('change', setPrice);
    currency.addEventListener('change', setPrice);
  }

  const paymentClient = qs('[data-payment-client]');
  const paymentSub = qs('[data-payment-sub]');
  if (paymentClient && paymentSub) {
    const moneyCurrency = qs('[data-money-currency]', paymentClient.form);
    const moneyAmount = qs('[data-money-amount]', paymentClient.form);
    const filterSubs = () => {
      const clientId = paymentClient.value;
      qsa('option', paymentSub).forEach((option) => {
        option.hidden = Boolean(option.value && clientId && option.dataset.client !== clientId);
      });
      if (paymentSub.selectedOptions[0]?.hidden) paymentSub.value = '';
    };
    paymentClient.addEventListener('change', () => {
      filterSubs();
      const preferred = paymentClient.selectedOptions[0]?.dataset.currency;
      if (preferred) {
        moneyCurrency.value = preferred;
        moneyCurrency.dispatchEvent(new Event('input'));
      }
    });
    paymentSub.addEventListener('change', () => {
      const selected = paymentSub.selectedOptions[0];
      if (!selected?.value) return;
      moneyCurrency.value = selected.dataset.currency;
      moneyAmount.value = selected.dataset.amount;
      moneyCurrency.dispatchEvent(new Event('input'));
    });
    filterSubs();
  }

  qsa('[data-chart]').forEach((chart) => {
    let data = [];
    try { data = JSON.parse(chart.dataset.chart); } catch (_) { return; }
    const max = Math.max(1, ...data.flatMap((item) => [Number(item.revenue), Number(item.cost)]));
    chart.innerHTML = data.map((item) => {
      const revenue = Math.max(2, (Number(item.revenue) / max) * 100);
      const cost = Math.max(2, (Number(item.cost) / max) * 100);
      return `<div class="chart-column"><div class="bars"><i class="revenue" style="height:${revenue}%" title="Entradas: ${formatBrl(item.revenue)}"></i><i class="cost" style="height:${cost}%" title="Saídas: ${formatBrl(item.cost)}"></i></div><span>${item.label}</span></div>`;
    }).join('');
  });
})();
