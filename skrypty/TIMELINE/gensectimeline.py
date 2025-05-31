import json

with open('timeline.json', 'r', encoding='utf-8') as f:
    timeline_data = json.load(f)

section_start = '''
<section class="py-24 bg-gray-50 relative overflow-hidden">
    <div class="max-w-[1400px] mx-auto px-6">
        <div class="text-center mb-20">
            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-accent">
                Kluczowe wydarzenia
            </h2>
            <p class="text-xl text-gray-600">
                Najważniejsze momenty w mojej karierze mówcy i mentora
            </p>
        </div>
        <div class="relative">
            <div class="absolute left-4 md:left-1/2 top-0 bottom-0 w-0.5 bg-accent/30 transform md:-translate-x-0.5"></div>
            <div class="space-y-12 timeline-mobile md:space-y-16">
'''

section_end = '''
            </div>
        </div>
    </div>
</section>
'''

items_html = []

for i, item in enumerate(timeline_data):
    rok = item['rok']
    tytul = item['tytuł']
    opis = item['opis']

    is_right = i % 2 == 1
    side_class = (
        'ml-12 md:ml-0 md:w-1/2 md:pr-12'
        if not is_right else
        'ml-12 md:ml-0 md:w-1/2 md:ml-auto md:pl-12'
    )
    extra_border = ' border-2 border-accent/20' if rok == 'Dziś' else ''
    dot_class = (
        'timeline-dot bg-gradient-to-r from-accent to-accent/80'
        if rok == 'Dziś' else
        'timeline-dot'
    )

    item_html = f'''
                <div class="flex items-center relative">
                    <div class="absolute left-0 md:left-1/2 transform md:-translate-x-1/2">
                        <div class="{dot_class}"></div>
                    </div>
                    <div class="{side_class} timeline-item">
                        <div class="morphing-card p-6{extra_border}">
                            <div class="text-accent font-bold text-lg mb-2">{rok}</div>
                            <h3 class="text-xl font-semibold mb-3">{tytul}</h3>
                            <p class="text-gray-700">{opis}</p>
                        </div>
                    </div>
                </div>
    '''
    items_html.append(item_html)

full_html = section_start + '\n'.join(items_html) + section_end

with open('output.html', 'w', encoding='utf-8') as f:
    f.write(full_html)

print("✅ Plik output.html został wygenerowany.")
