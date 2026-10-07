const header = document.querySelector('.site-header');
const menuToggle = document.querySelector('.menu-toggle');

if (header && menuToggle) {
  menuToggle.addEventListener('click', () => {
    const isOpen = header.classList.toggle('menu-open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
  });

  header.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      header.classList.remove('menu-open');
      menuToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

document.querySelectorAll('.year').forEach((node) => {
  node.textContent = new Date().getFullYear();
});

const modal = document.querySelector('.modal');

function openModal(trigger) {
  if (!modal) return;
  const image = modal.querySelector('.modal-image img');
  const title = modal.querySelector('.modal-copy h2');
  const description = modal.querySelector('.modal-copy p');
  const action = modal.querySelector('.modal-copy .button');

  image.src = trigger.dataset.image || '';
  image.alt = trigger.dataset.title || '';
  title.textContent = trigger.dataset.title || '';
  description.textContent = trigger.dataset.description || '';
  action.dataset.order = trigger.dataset.title || '';
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
  modal.querySelector('.modal-close').focus();
}

function closeModal() {
  if (!modal) return;
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('modal-open');
}

document.querySelectorAll('.js-modal').forEach((trigger) => {
  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    openModal(trigger);
  });
});

if (modal) {
  modal.querySelector('.modal-close').addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });
}

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeModal();
});

document.addEventListener('click', (event) => {
  const orderButton = event.target.closest('[data-order]');
  if (!orderButton) return;

  event.preventDefault();
  const form = document.querySelector('#request-form');
  if (!form) return;

  const comment = form.querySelector('[name="comment"]');
  const itemName = orderButton.dataset.order;
  if (comment && itemName) {
    // Не теряем VIN и другие сведения, уже введённые посетителем.
    const details = comment.value.replace(/^Интересует:.*(?:\r?\n)?/, '').trim();
    comment.value = `Интересует: ${itemName}${details ? `\n${details}` : ''}`;
  }

  closeModal();
  form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  window.setTimeout(() => form.querySelector('[name="name"]')?.focus(), 450);
});

document.querySelectorAll('.request-form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const status = form.querySelector('.form-status');
    if (status) {
      status.textContent = 'Заявка не отправлена: это демонстрационная форма. Для заказа позвоните 8 925 333-99-33 или напишите на to@4sale.ru.';
    }
  });
});
