const initTestimonials = () => {
    const track = document.querySelector('.testimonials-track');
    const cards = document.querySelectorAll('.testimonial-card');
    const navContainer = document.querySelector('.testimonial-navigation');
    let currentIndex = 0;

    const createNavigationDots = () => {
        cards.forEach((_, index) => {
            const dot = document.createElement('div');
            dot.classList.add('nav-dot');
            if (index === 0) dot.classList.add('active');
            dot.addEventListener('click', () => goToSlide(index));
            navContainer.appendChild(dot);
        });
    };

    const updateDots = () => {
        document.querySelectorAll('.nav-dot').forEach((dot, index) => {
            dot.classList.toggle('active', index === currentIndex);
        });
    };

    const goToSlide = (index) => {
        currentIndex = index;
        track.style.transform = `translateX(-${index * 100}%)`;
        cards.forEach((card, i) => card.classList.toggle('active', i === index));
        updateDots();
    };

    createNavigationDots();
    setInterval(() => goToSlide((currentIndex + 1) % cards.length), 7000);
};

const initBioAnimations = () => {
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    // Mobile animations
    if (isMobile) {
        gsap.from('.mobile-background', {
            scale: 1.2,
            duration: 1.5,
            ease: 'power2.out'
        });

        gsap.from('.mobile-overlay', {
            opacity: 0,
            duration: 1,
            ease: 'power2.out'
        });

        gsap.from('.mobile-content', {
            y: 50,
            opacity: 0,
            duration: 1,
            delay: 0.5,
            ease: 'power3.out'
        });
    }
    // Desktop animations
    else {
        // Image and stats box animation
        gsap.from('.floating', {
            scale: 0.8,
            opacity: 0,
            duration: 1.5,
            ease: 'power3.out'
        });

        gsap.from('.floating img', {
            scale: 1.2,
            rotate: -10,
            duration: 1.5,
            ease: 'power3.out'
        });

        // Create a continuous floating animation
        gsap.to('.floating', {
            y: '-20px',
            duration: 2,
            ease: 'power1.inOut',
            yoyo: true,
            repeat: -1
        });

        // Stats box animation
        gsap.from('.-bottom-8.-left-8', {
            x: -50,
            y: 50,
            opacity: 0,
            duration: 1,
            delay: 0.8,
            ease: 'power3.out'
        });

        // Text animations
        const textContent = document.querySelector('.space-y-8');

        gsap.from('h1.text-6xl', {
            x: -100,
            opacity: 0,
            duration: 1,
            ease: 'power3.out'
        });

        gsap.from('.absolute.-bottom-4', {
            width: 0,
            duration: 1,
            delay: 0.3,
            ease: 'power3.inOut'
        });

        gsap.from(textContent.querySelector('p'), {
            y: 30,
            opacity: 0,
            duration: 1,
            delay: 0.5,
            ease: 'power3.out'
        });
    }

    // Navbar animation
    gsap.from('nav', {
        y: -100,
        opacity: 0,
        duration: 1,
        ease: 'power3.out'
    });

    // Scroll-triggered animations
    ScrollTrigger.create({
        trigger: '.desktop-bio',
        start: 'top center',
        onEnter: () => {
            gsap.to('.grain-overlay', {
                opacity: 0.1,
                duration: 1,
                ease: 'power2.out'
            });
        },
        onLeave: () => {
            gsap.to('.grain-overlay', {
                opacity: 0,
                duration: 1,
                ease: 'power2.out'
            });
        },
        toggleActions: 'play reverse play reverse'
    });
};

const initGallery = () => {
    const modal = document.getElementById('imageModal');
    const modalImg = modal.querySelector('.modal-image-container img');
    const modalTitle = modal.querySelector('.modal-title');
    const modalDescription = modal.querySelector('.modal-description');
    const galleryItems = document.querySelectorAll('.gallery-item');
    const modalClose = document.querySelector('.modal-close');
    const modalPrev = document.querySelector('.modal-prev');
    const modalNext = document.querySelector('.modal-next');
    let currentIndex = 0;
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    const galleryData = Array.from(galleryItems).map(item => ({
        src: item.querySelector('img').src,
        alt: item.querySelector('img').alt,
        title: item.querySelector('h3').textContent,
        description: item.querySelector('p').textContent
    }));

    const updateModal = (index) => {
        const item = galleryData[index];

        gsap.to(modalImg, {
            opacity: 0,
            duration: 0.2,
            ease: "power1.out",
            onComplete: () => {
                modalImg.src = item.src;
                modalImg.alt = item.alt;
                modalTitle.textContent = item.title;
                modalDescription.textContent = item.description;

                gsap.to(modalImg, {
                    opacity: 1,
                    duration: 0.2,
                    ease: "power1.out"
                });
            }
        });
    };

    const closeModal = () => {
        gsap.to(modal, {
            opacity: 0,
            duration: 0.2,
            ease: "power1.out",
            onComplete: () => {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                modal.style.opacity = '';
            }
        });
    };

    const openModal = (index) => {
        currentIndex = index;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        const item = galleryData[index];
        modalImg.style.opacity = '0';
        modalImg.src = item.src;
        modalImg.alt = item.alt;
        modalTitle.textContent = item.title;
        modalDescription.textContent = item.description;

        gsap.to(modalImg, {
            opacity: 1,
            scale: 1,
            duration: 0.3,
            ease: "power2.out",
            delay: 0.1
        });
    };

    const initModalControls = () => {
        modalClose.addEventListener('click', closeModal);
        modalPrev.addEventListener('click', () => {
            currentIndex = (currentIndex - 1 + galleryData.length) % galleryData.length;
            updateModal(currentIndex);
        });
        modalNext.addEventListener('click', () => {
            currentIndex = (currentIndex + 1) % galleryData.length;
            updateModal(currentIndex);
        });
    };

    const initKeyboardNav = () => {
        document.addEventListener('keydown', (e) => {
            if (!modal.classList.contains('active')) return;

            const keyActions = {
                'ArrowLeft': () => modalPrev.click(),
                'ArrowRight': () => modalNext.click(),
                'Escape': () => modalClose.click()
            };

            keyActions[e.key]?.();
        });
    };

    const initGalleryItems = () => {
        galleryItems.forEach((item, index) => {
            const overlay = item.querySelector('.gallery-overlay');
            const content = item.querySelector('.gallery-content');
            const img = item.querySelector('img');

            if (isMobile) {
                gsap.set(overlay, { opacity: 1 });
                gsap.set(content, { opacity: 1, y: 0 });
                gsap.set(img, { scale: 1 });
            } else {
                gsap.set(overlay, { opacity: 0 });
                gsap.set(content, { opacity: 0, y: 10 });
                gsap.set(img, { scale: 1 });

                const enterAnimation = () => {
                    gsap.to(overlay, {
                        opacity: 1,
                        duration: 0.2,
                        ease: "power1.out"
                    });
                    gsap.to(content, {
                        y: 0,
                        opacity: 1,
                        duration: 0.2,
                        ease: "power2.out"
                    });
                    gsap.to(img, {
                        scale: 1.05,
                        duration: 0.3,
                        ease: "power1.out"
                    });
                };

                const leaveAnimation = () => {
                    gsap.to([overlay, content], {
                        opacity: 0,
                        duration: 0.2,
                        ease: "power1.in"
                    });
                    gsap.to(content, {
                        y: 10,
                        duration: 0.2,
                        ease: "power2.in"
                    });
                    gsap.to(img, {
                        scale: 1,
                        duration: 0.3,
                        ease: "power1.in"
                    });
                };

                item.addEventListener('mouseenter', enterAnimation);
                item.addEventListener('mouseleave', leaveAnimation);
            }

            item.addEventListener('click', () => openModal(index));
        });
    };

    window.addEventListener('resize', () => {
        const newIsMobile = window.matchMedia('(max-width: 768px)').matches;
        if (newIsMobile !== isMobile) {
            window.location.reload();
        }
    });

    // Zatrzymuje uruchomienie zoomu przy kliknięciu na linki w galerii
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.gallery-zoom a').forEach(link => {
            link.addEventListener('click', event => {
                event.stopPropagation(); // zatrzymuje uruchomienie zoomu
            });
        });
    });


    initModalControls();
    initKeyboardNav();
    initGalleryItems();
};

gsap.registerPlugin(ScrollTrigger);
initTestimonials();
initGallery();
initBioAnimations();
lucide.createIcons();