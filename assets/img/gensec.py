import os
import re
from PIL import Image

template = '''
<section class="py-20 bg-light">
    <div class="max-w-7xl mx-auto px-6">
        <div class="gallery-grid">
{items}
        </div>
    </div>
</section>
'''

item_template = '''
            <div class="gallery-item{css_class}" data-aos="fade-up" data-aos-delay="{delay}">
                <img src="./assets/img/{webp}" alt="{alt}">
                <div class="gallery-overlay">
                    <div class="gallery-content">
                        <h3 class="text-xl font-bold mb-2">{title}</h3>
                        <p class="text-sm opacity-90">{description}</p>
                        <div class="gallery-zoom">
                            <i data-lucide="zoom-in" class="h-6 w-6"></i>
                            <span>Kliknij aby zobaczyć</span>
                            <a target="_blank" href="./assets/img/{jpg}" download class="ml-4 text-sm underline">Pobierz ({dimensions}, {size})</a>
                        </div>
                    </div>
                </div>
            </div>'''

def extract_year(filename):
    match = re.search(r'(19|20)\d{2}', filename)
    return match.group(0) if match else 'Rok?'

webp_files = sorted(f for f in os.listdir('.') if f.lower().endswith('.webp'))

html_items = []
for i, webp in enumerate(webp_files):
    base_name = os.path.splitext(webp)[0]
    jpg = base_name + '.jpg'
    delay = i * 100

    try:
        jpg_path = jpg
        if not os.path.isfile(jpg_path):
            print(f"⚠️ Brakuje pliku JPG: {jpg_path}")
            continue

        with Image.open(jpg_path) as img:
            width, height = img.size
            ratio = width / height
            dimensions = f"{width}x{height}"

            css_class = ''
            if ratio >= 1.6:
                css_class = ' wide'
            elif (1 / ratio) >= 1.1:
                css_class = ' tall'

        # Oblicz rozmiar pliku JPG
        jpg_size_bytes = os.path.getsize(jpg_path)
        jpg_size_mb = jpg_size_bytes / (1024 * 1024)
        jpg_size_str = f"{jpg_size_mb:.1f}".replace('.', ',') + "MB"

        title = extract_year(base_name)
        description = base_name

    except Exception as e:
        print(f"❌ Błąd przy pliku {webp}: {e}")
        continue

    html_items.append(item_template.format(
        webp=webp,
        jpg=jpg,
        css_class=css_class,
        delay=delay,
        title=title,
        description=description,
        alt=description,
        dimensions=dimensions,
        size=jpg_size_str
    ))

output_html = template.format(items=''.join(html_items))

with open('output.html', 'w', encoding='utf-8') as f:
    f.write(output_html)

print("✅ Gotowe! Galeria z rokiem, rozdzielczością i rozmiarem JPG zapisana jako 'output.html'")
