(() => {
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];

  qsa('[data-menu]').forEach((button) => button.addEventListener('click', () => {
    document.body.classList.toggle('menu-open');
  }));

  qsa('[data-alert] button').forEach((button) => button.addEventListener('click', () => button.parentElement.remove()));
  qsa('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  }));

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

    const calculate = () => {
      const multiplier = currency.value === 'USD' ? Number(rate.value || currentRate) : 1;
      const net = Math.max(0, Number(amount.value || 0) - Number(fee?.value || 0));
      preview.textContent = formatBrl(net * multiplier);
      rate.readOnly = currency.value === 'BRL';
      rate.closest('label')?.classList.toggle('field-disabled', currency.value === 'BRL');
      if (currency.value === 'BRL') rate.value = '1';
      else if (Number(rate.value) === 1) rate.value = currentRate;
    };
    [currency, amount, fee, rate].filter(Boolean).forEach((input) => input.addEventListener('input', calculate));
    calculate();
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

