const header = document.getElementById('siteHeader');
const progress = document.getElementById('scrollProgress');
const menuButton = document.getElementById('menuButton');
const nav = document.getElementById('mainNav');
const calibre = document.getElementById('calibre');
const calibreWrap = document.getElementById('calibreWrap');
const calibreState = document.getElementById('calibreState');
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const finePointer = window.matchMedia('(pointer: fine)').matches;

document.getElementById('year').textContent = new Date().getFullYear();

let scrollFrame = false;
function updateScroll() {
  const scrollMax = document.documentElement.scrollHeight - window.innerHeight;
  const ratio = scrollMax > 0 ? Math.min(1, Math.max(0, window.scrollY / scrollMax)) : 0;
  header.classList.toggle('scrolled', window.scrollY > 20);
  progress.style.transform = `scaleX(${ratio})`;
  scrollFrame = false;
}

window.addEventListener('scroll', () => {
  if (!scrollFrame) {
    scrollFrame = true;
    requestAnimationFrame(updateScroll);
  }
}, { passive: true });
updateScroll();

function setMenu(open) {
  nav.classList.toggle('is-open', open);
  document.body.classList.toggle('menu-open', open);
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
}

menuButton.addEventListener('click', () => setMenu(menuButton.getAttribute('aria-expanded') !== 'true'));
nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenu(false)));
window.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });

document.querySelectorAll('.offer-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    const target = tab.dataset.offer;
    document.querySelectorAll('.offer-tab').forEach(item => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('.offer-panel').forEach(panel => {
      const active = panel.dataset.panel === target;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
  });
});

function initCalibreMotion() {
  if (!calibre || !calibreWrap) return;

  // On touch devices the CSS keyframes carry every bit of the mechanism's
  // motion. Skip the per-frame JS parallax loop entirely — recomputing the
  // whole preserve-3d subtree each frame is what makes phones stutter.
  if (!finePointer) {
    calibre.style.setProperty('--motion-rx', '0deg');
    calibre.style.setProperty('--motion-ry', '0deg');
    calibre.style.setProperty('--motion-rz', '0deg');
    calibre.style.setProperty('--motion-y', '0px');
    return;
  }

  let pointerInside = false;
  let pointerX = 0;
  let pointerY = 0;
  let rotationX = 0;
  let rotationY = 0;
  let rotationZ = 0;
  let floatY = 0;
  let lastTime = performance.now();
  let frameId = 0;
  let running = false;
  let visible = true;
  const ambientScale = reduceMotion ? .46 : 1;
  const interactionScale = reduceMotion ? .3 : 1;

  const damp = (current, target, lambda, delta) => current + (target - current) * (1 - Math.exp(-lambda * delta));

  function render(time) {
    if (!running) return;
    const delta = Math.min((time - lastTime) / 1000, .05);
    const seconds = time / 1000;
    lastTime = time;

    const idleX = Math.sin(seconds * .37) * .48 * ambientScale;
    const idleY = Math.cos(seconds * .29) * .72 * ambientScale;
    const idleZ = Math.sin(seconds * .21) * .28 * ambientScale;
    const pointerTiltX = pointerInside ? pointerY * -3.2 * interactionScale : 0;
    const pointerTiltY = pointerInside ? pointerX * 4.1 * interactionScale : 0;

    rotationX = damp(rotationX, idleX + pointerTiltX, 1.55, delta);
    rotationY = damp(rotationY, idleY + pointerTiltY, 1.55, delta);
    rotationZ = damp(rotationZ, idleZ, 1.15, delta);
    floatY = damp(floatY, Math.sin(seconds * .52) * 2.2 * ambientScale, 1.3, delta);

    calibre.style.setProperty('--motion-rx', `${rotationX.toFixed(3)}deg`);
    calibre.style.setProperty('--motion-ry', `${rotationY.toFixed(3)}deg`);
    calibre.style.setProperty('--motion-rz', `${rotationZ.toFixed(3)}deg`);
    calibre.style.setProperty('--motion-y', `${floatY.toFixed(3)}px`);

    frameId = requestAnimationFrame(render);
  }

  function start() {
    if (running || !visible || document.hidden) return;
    running = true;
    lastTime = performance.now();
    frameId = requestAnimationFrame(render);
  }

  function stop() {
    running = false;
    cancelAnimationFrame(frameId);
  }

  if (finePointer) {
    calibreWrap.addEventListener('pointerenter', () => { pointerInside = true; });
    calibreWrap.addEventListener('pointermove', event => {
      const bounds = calibreWrap.getBoundingClientRect();
      pointerX = Math.max(-.5, Math.min(.5, (event.clientX - bounds.left) / bounds.width - .5));
      pointerY = Math.max(-.5, Math.min(.5, (event.clientY - bounds.top) / bounds.height - .5));
      calibreState.textContent = 'ОТКЛИК';
    }, { passive: true });
    calibreWrap.addEventListener('pointerleave', () => {
      pointerInside = false;
      pointerX = 0;
      pointerY = 0;
      calibreState.textContent = 'СТАБИЛЬНО';
    });
  }

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      visible = entries[0]?.isIntersecting ?? true;
      if (visible) start(); else stop();
    }, { threshold: .02 });
    observer.observe(calibreWrap);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop(); else start();
  });

  start();
}

initCalibreMotion();

if ('IntersectionObserver' in window && !reduceMotion) {
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: .12, rootMargin: '0px 0px -35px' });
  document.querySelectorAll('.reveal').forEach(element => observer.observe(element));
} else {
  document.querySelectorAll('.reveal').forEach(element => element.classList.add('is-visible'));
}

// ----------------------------------------------------
// Contact Form ('Обсудить проект')
// ----------------------------------------------------
function initProjectForm() {
  const form = document.getElementById('projectForm');
  const feedback = document.getElementById('formFeedback');
  const errorMsg = document.getElementById('formErrorMsg');
  if (!form || !feedback) return;

  const chips = document.querySelectorAll('.form-chip');
  const serviceInput = document.getElementById('formService');
  const submitBtn = document.getElementById('formSubmitBtn');
  const tgLinkEl = document.getElementById('feedbackTgLink');
  const resetBtn = document.getElementById('feedbackResetBtn');
  const honeypot = document.getElementById('formHoneypot');

  function getErrorEl() {
    let el = document.getElementById('formErrorMsg');
    if (!el && form) {
      el = document.createElement('div');
      el.id = 'formErrorMsg';
      el.className = 'form-error-msg';
      el.setAttribute('role', 'alert');
      const actions = form.querySelector('.form-actions');
      if (actions) actions.prepend(el);
    }
    return el;
  }

  function hideError() {
    const el = document.getElementById('formErrorMsg');
    if (el) {
      el.textContent = '';
      el.hidden = true;
      el.classList.remove('is-visible');
    }
  }

  function showError(msg) {
    const el = getErrorEl();
    if (el) {
      el.textContent = msg;
      el.hidden = false;
      el.classList.add('is-visible');
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
      alert(msg);
    }
  }

  // Chips selection
  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      chips.forEach(c => {
        c.classList.remove('is-active');
        c.setAttribute('aria-checked', 'false');
      });
      chip.classList.add('is-active');
      chip.setAttribute('aria-checked', 'true');
      if (serviceInput) {
        serviceInput.value = chip.dataset.value || chip.textContent.trim();
      }
    });
  });

  // Remove invalid state on input
  form.querySelectorAll('.form-input, .form-textarea').forEach(input => {
    input.addEventListener('input', () => {
      input.classList.remove('is-invalid');
      hideError();
    });
  });

  // Form submission
  form.addEventListener('submit', async event => {
    event.preventDefault();
    hideError();

    const nameInput = document.getElementById('formName');
    const emailInput = document.getElementById('formEmail');
    const contactInput = document.getElementById('formContact');
    const taskInput = document.getElementById('formTask');

    let hasError = false;
    [nameInput, emailInput, taskInput].forEach(field => {
      if (!field || !field.value.trim()) {
        field?.classList.add('is-invalid');
        hasError = true;
      }
    });

    if (emailInput && emailInput.value.trim() && !emailInput.validity.valid) {
      emailInput.classList.add('is-invalid');
      hasError = true;
    }

    if (hasError) {
      const firstInvalid = form.querySelector('.is-invalid');
      firstInvalid?.focus();
      return;
    }

    // Submit state
    const originalBtnHtml = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span>Отправка заявки...</span>';

    const payload = {
      service: serviceInput?.value || 'Проект',
      name: nameInput.value.trim(),
      email: emailInput.value.trim(),
      contact: contactInput ? contactInput.value.trim() : '',
      task: taskInput.value.trim(),
      _hp_company: honeypot ? honeypot.value.trim() : ''
    };

    try {
      const response = await fetch('send.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const result = await response.json().catch(() => null);

      if (!response.ok || !result || !result.success) {
        const errorText = (result && result.error)
          ? result.error
          : 'Не удалось отправить заявку. Пожалуйста, напишите нам напрямую в Telegram @rdk_it или на kontakt@rdk-ai.com.';
        showError(errorText);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnHtml;
        return;
      }

      // Success
      if (tgLinkEl) {
        const msg = `Здравствуйте! Отправил заявку с сайта. Направление: ${payload.service}. Имя: ${payload.name}.`;
        tgLinkEl.href = `https://t.me/rdk_it?text=${encodeURIComponent(msg)}`;
      }

      form.style.display = 'none';
      feedback.hidden = false;
      feedback.classList.add('is-visible');

      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnHtml;

      feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (err) {
      showError('Ошибка связи с сервером. Пожалуйста, проверьте подключение или напишите нам в Telegram @rdk_it.');
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnHtml;
    }
  });

  // Reset form to send another task
  resetBtn?.addEventListener('click', () => {
    form.reset();
    form.style.display = '';
    feedback.hidden = true;
    feedback.classList.remove('is-visible');
    hideError();
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    chips[0]?.click();
  });
}

initProjectForm();


