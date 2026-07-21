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

  const productPricingForm = qs('[data-product-pricing]');
  if (productPricingForm) {
    const mode = qs('[data-pricing-mode]', productPricingForm);
    const brl = qs('[data-price-brl]', productPricingForm);
    const usd = qs('[data-price-usd]', productPricingForm);
    const brlLabel = qs('[data-price-brl-label]', productPricingForm);
    const usdLabel = qs('[data-price-usd-label]', productPricingForm);
    const brlHelp = qs('[data-price-brl-help]', productPricingForm);
    const usdHelp = qs('[data-price-usd-help]', productPricingForm);
    const rate = Math.max(0.000001, Number(productPricingForm.dataset.currentRate || 1));

    const convert = () => {
      if (mode.value === 'usd') brl.value = (Number(usd.value || 0) * rate).toFixed(2);
      if (mode.value === 'brl') usd.value = (Number(brl.value || 0) / rate).toFixed(2);
    };
    const updateMode = () => {
      const manual = mode.value === 'manual';
      const brlBase = mode.value === 'brl';
      brl.readOnly = mode.value === 'usd';
      usd.readOnly = brlBase;
      brl.required = manual || brlBase;
      usd.required = manual || mode.value === 'usd';
      brlLabel.classList.toggle('calculated-price', mode.value === 'usd');
      usdLabel.classList.toggle('calculated-price', brlBase);
      brlHelp.textContent = mode.value === 'usd' ? 'Calculado automaticamente pela cotação diária' : (brlBase ? 'Preço-base do produto' : 'Preço local informado manualmente');
      usdHelp.textContent = brlBase ? 'Calculado automaticamente pela cotação diária' : (mode.value === 'usd' ? 'Preço-base do produto' : 'Preço local informado manualmente');
      if (!manual) convert();
    };

    brl.addEventListener('input', () => { if (mode.value === 'brl') convert(); });
    usd.addEventListener('input', () => { if (mode.value === 'usd') convert(); });
    mode.addEventListener('change', updateMode);
    updateMode();
  }

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

  const renewalForm = qs('[data-renewal-form]');
  if (renewalForm) {
    const rows = qsa('[data-renewal-row]', renewalForm);
    const checkAllRenewals = qs('[data-renewal-check-all]', renewalForm);
    const selectedLabel = qs('[data-renewal-selected]', renewalForm);
    const submitRenewals = qs('[data-renewal-submit]', renewalForm);
    const cycleMonths = { monthly: 1, quarterly: 3, semiannual: 6, annual: 12 };

    const formatCurrency = (value, currency) => new Intl.NumberFormat('pt-BR', {
      style: 'currency', currency
    }).format(value || 0);
    const addMonths = (dateValue, months) => {
      const [year, month, day] = dateValue.split('-').map(Number);
      const lastDay = new Date(year, month - 1 + months + 1, 0).getDate();
      const target = new Date(year, month - 1 + months, Math.min(day, lastDay), 12);
      return target.toLocaleDateString('pt-BR');
    };

    const refreshForm = () => {
      const selectedRows = rows.filter((row) => qs('[data-renewal-check]', row).checked);
      const invalidRows = selectedRows.filter((row) => row.dataset.valid !== '1');
      selectedLabel.textContent = selectedRows.length;
      checkAllRenewals.checked = selectedRows.length === rows.length;
      checkAllRenewals.indeterminate = selectedRows.length > 0 && selectedRows.length < rows.length;
      submitRenewals.disabled = selectedRows.length === 0 || invalidRows.length > 0;
      submitRenewals.textContent = invalidRows.length > 0
        ? `Revise ${invalidRows.length} valor(es) divergente(s)`
        : `Confirmar e receber ${selectedRows.length} renovação(ões)`;
    };

    rows.forEach((row) => {
      const check = qs('[data-renewal-check]', row);
      const product = qs('[data-renewal-product]', row);
      const currency = qs('[data-renewal-currency]', row);
      const quantity = qs('[data-renewal-quantity]', row);
      const price = qs('[data-renewal-price]', row);
      const discount = qs('[data-renewal-discount]', row);
      const amount = qs('[data-renewal-amount]', row);
      const due = qs('[data-renewal-due]', row);
      const receipt = qs('[data-renewal-receipt]', row);
      const total = qs('[data-renewal-total]', row);
      const balance = qs('[data-renewal-balance]', row);
      const useTotal = qs('[data-renewal-use-total]', row);
      const match = qs('[data-renewal-match]', row);
      const next = qs('[data-renewal-next]', row);
      const timing = qs('[data-payment-timing]', row);

      const calculate = () => {
        const expected = Math.max(0, (Number(price.value || 0) * Number(quantity.value || 0)) - Number(discount.value || 0));
        const received = Number(amount.value || 0);
        const difference = received - expected;
        row.dataset.expected = expected.toFixed(2);
        const matches = expected > 0 && Math.abs(difference) < 0.009;
        total.textContent = formatCurrency(expected, currency.value);
        balance.textContent = matches ? 'Valor integral confirmado' : `Diferença: ${formatCurrency(difference, currency.value)}`;
        balance.className = matches ? 'positive' : 'negative';
        match.textContent = matches ? '✓ Valor confere' : '! Valor divergente';
        match.classList.toggle('invalid', !matches);
        row.dataset.valid = matches ? '1' : '0';

        const months = cycleMonths[product.selectedOptions[0]?.dataset.cycle] || 1;
        next.textContent = addMonths(due.value, months);
        if (receipt.value < due.value) {
          timing.textContent = 'Pagamento antecipado';
          timing.className = 'early';
        } else if (receipt.value > due.value) {
          timing.textContent = 'Pagamento em atraso';
          timing.className = 'late';
        } else {
          timing.textContent = 'Pago no vencimento';
          timing.className = 'on-time';
        }
        refreshForm();
      };
      const setProductPrice = () => {
        const option = product.selectedOptions[0];
        price.value = currency.value === 'USD' ? option.dataset.usd : option.dataset.brl;
        calculate();
      };

      [quantity, price, discount, amount, receipt].forEach((input) => input.addEventListener('input', calculate));
      useTotal.addEventListener('click', () => {
        amount.value = row.dataset.expected;
        calculate();
      });
      product.addEventListener('change', setProductPrice);
      currency.addEventListener('change', setProductPrice);
      check.addEventListener('change', refreshForm);
      calculate();
    });

    checkAllRenewals.addEventListener('change', () => {
      rows.forEach((row) => { qs('[data-renewal-check]', row).checked = checkAllRenewals.checked; });
      refreshForm();
    });
    refreshForm();
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
