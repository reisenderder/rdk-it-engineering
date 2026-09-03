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
