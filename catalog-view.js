(() => {
  const brand = new URLSearchParams(location.search).get('brand');
  const products = [...document.querySelectorAll('[data-brand]')];
  let count = 0;
  products.forEach(item => { item.hidden = Boolean(brand && item.dataset.brand !== brand); if (!item.hidden) count++; });
  const empty = document.querySelector('#catalog-empty');
  if (empty) empty.hidden = count > 0;
  if (brand) { const heading = document.querySelector('#catalog h1'); if (heading) heading.textContent = `${heading.textContent} · ${brand}`; }
})();
