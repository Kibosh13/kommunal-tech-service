(() => {
  const id = new URLSearchParams(window.location.search).get('part');
  const catalog = window.partsCatalog;
  const product = catalog && Object.hasOwn(catalog, id) ? catalog[id] : null;
  const element = (name) => document.getElementById(name);
  if (!product) {
    element('product-missing').hidden = false;
    document.title = 'Запчасть не найдена — Выездной сервис';
    return;
  }

  document.title = `${product.title}${product.sku ? ` ${product.sku}` : ''} — Выездной сервис`;
  document.querySelector('meta[name="description"]').content = product.summary;
  element('product-detail').hidden = false;
  element('product-catalog-link').href = product.brand === 'HIDRO-MAK' ? 'hidromak.html' : 'katmerciler.html';
  element('product-catalog-link').textContent = product.brand;
  element('product-breadcrumb').textContent = product.title;
  element('product-title').textContent = product.title;
  element('product-category').textContent = product.category;
  element('product-sku').textContent = product.sku ? `Артикул: ${product.sku}` : 'Артикул уточняется при подборе';
  element('product-brand').textContent = `Техника: ${product.brand}`;
  element('product-summary').textContent = product.summary;
  element('product-selection').textContent = product.selection;

  const orderName = `${product.title}${product.sku ? ` · ${product.sku}` : ''} (${product.brand})`;
  element('product-order').dataset.order = orderName;
  element('product-selection-order').dataset.order = orderName;
  document.querySelector('#request-form [name="comment"]').value = `Интересует: ${orderName}\n`;
  product.description.forEach((text) => {
    const paragraph = document.createElement('p');
    paragraph.textContent = text;
    element('product-description').append(paragraph);
  });
  product.specs.forEach(([label, value]) => {
    const row = document.createElement('div');
    const term = document.createElement('dt');
    const description = document.createElement('dd');
    term.textContent = label;
    description.textContent = value;
    row.append(term, description);
    element('product-specs').append(row);
  });

  const dialog = element('photo-dialog');
  let activePhoto = 0;
  const thumbnails = product.photos.map((photo, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', `Показать изображение ${index + 1}: ${photo.alt}`);
    const image = document.createElement('img');
    image.src = photo.src;
    image.alt = '';
    button.append(image);
    button.addEventListener('click', () => showPhoto(index));
    element('product-thumbnails').append(button);
    return button;
  });

  function showPhoto(index) {
    activePhoto = (index + product.photos.length) % product.photos.length;
    const photo = product.photos[activePhoto];
    ['product-image', 'photo-dialog-image'].forEach((name) => {
      element(name).src = photo.src;
      element(name).alt = photo.alt;
    });
    const caption = `${product.diagram ? 'Каталожная схема' : 'Фото'} ${activePhoto + 1} из ${product.photos.length}`;
    element('product-photo-caption').textContent = `${caption}. ${product.diagram ? 'Позиции уточняются при подборе.' : 'Изображение из исходного каталога.'}`;
    element('photo-dialog-caption').textContent = caption;
    thumbnails.forEach((button, photoIndex) => button.setAttribute('aria-pressed', String(photoIndex === activePhoto)));
  }

  ['photo-previous', 'photo-next'].forEach((name) => {
    element(name).hidden = product.photos.length < 2;
  });
  element('photo-previous').addEventListener('click', () => showPhoto(activePhoto - 1));
  element('photo-next').addEventListener('click', () => showPhoto(activePhoto + 1));
  element('product-photo-open').addEventListener('click', () => {
    dialog.showModal();
    document.body.classList.add('modal-open');
    element('photo-dialog-close').focus();
  });
  element('photo-dialog-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => document.body.classList.remove('modal-open'));
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
  });
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      showPhoto(activePhoto + (event.key === 'ArrowLeft' ? -1 : 1));
    }
  });
  showPhoto(0);
})();
