import * as pdfjsLib from '/assets/js/pdf.mjs';

const baseFlipbookConfig = {
  size: 'stretch',
  singlePageMode: true,
  maxShadowOpacity: 0.5,
  showCover: false,
  drawShadow: true,
  flippingTime: 800,
  usePortrait: true,
  mobileScrollSupport: true,
};

function buildMarkup() {
  return `
    <div class="pdf-book-shell" style="width: 100vw; margin-left: calc(-50vw + 50%); height: 100vh;">
      <div data-book-target class="pdf-book-canvas" style="width: 100%; height: calc(100vh - 8rem);"></div>
      <div class="pdf-book-status" data-book-status>Loading book...</div>
    </div>
  `;
}

async function openBook(pdfName, elementId) {
  const section = document.getElementById(elementId);
  if (!section) return;

  const pdfPath = pdfName.startsWith('/') ? pdfName : `/docs/${pdfName}`;
  section.innerHTML = buildMarkup();

  const viewer = section.querySelector('[data-book-target]');
  const status = section.querySelector('[data-book-status]');
  if (!viewer) return;

  if (status) status.textContent = 'Loading pages...';

  const pdfjs = pdfjsLib;
  if (!pdfjs) {
    if (status) status.textContent = 'Brak biblioteki pdf.js (pdfjsLib).';
    return;
  }
  if (pdfjs.GlobalWorkerOptions) {
    pdfjs.GlobalWorkerOptions.workerSrc = '/assets/js/pdf.worker.min.mjs';
  }

  viewer.innerHTML = '';
  viewer.style.height = '100%';
  viewer.style.width = '100%';

  if (section._pageFlipInstance) {
    section._pageFlipInstance.destroy();
    section._pageFlipInstance = null;
  }

  try {
    const doc = await pdfjs.getDocument({ url: encodeURI(pdfPath) }).promise;
    const firstPage = await doc.getPage(1);
    const baseViewport = firstPage.getViewport({ scale: 1 });
    const pageRatio = baseViewport.height / baseViewport.width;

    const isMobile = (window.innerWidth || 0) < 768;
    const targetWidth = viewer.clientWidth || window.innerWidth || 1200;
    let targetHeight = viewer.clientHeight || (window.innerHeight ? window.innerHeight - 150 : 800);
    if (isMobile) {
      targetHeight = Math.min(targetHeight, Math.round(targetWidth * pageRatio));
    }

    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const minRenderWidth = 1200;
    const maxRenderWidth = 2800;
    const renderWidth = Math.min(maxRenderWidth, Math.max(minRenderWidth, targetWidth * dpr));
    const renderHeight = renderWidth * pageRatio;

    const images = [];
    for (let i = 1; i <= doc.numPages; i++) {
      const page = i === 1 ? firstPage : await doc.getPage(i);
      const pageViewport = page.getViewport({ scale: 1 });
      const scale = Math.min(
        renderWidth / pageViewport.width,
        renderHeight / pageViewport.height
      );
      const viewport = page.getViewport({ scale });
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      await page.render({ canvasContext: ctx, viewport }).promise;
      images.push(canvas.toDataURL('image/jpeg', 0.92));
    }

    if (status) status.textContent = 'Initializing flipbook...';
    const flip = new St.PageFlip(viewer, {
      ...baseFlipbookConfig,
      width: targetWidth,
      height: targetHeight,
      minWidth: Math.max(300, Math.floor(targetWidth * 0.5)),
      minHeight: Math.max(200, Math.floor(targetHeight * 0.5)),
    });
    flip.loadFromImages(images);
    section._pageFlipInstance = flip;
    if (status) status.textContent = '' + "rozdzielczosc: " + renderWidth + "x" + renderHeight;
  } catch (err) {
    console.error(err);
    if (status) status.textContent = 'Nie udalo sie zaladowac PDF.';
  }
}

window.openBook = openBook;
export { openBook };
