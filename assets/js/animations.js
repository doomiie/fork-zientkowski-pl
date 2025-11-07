class Navigation {
  constructor() {
    this.mainNav = document.querySelector('.main-nav');
    this.mobileMenuBtn = document.querySelector('[data-mobile-menu-btn]');
    this.mobileMenu = document.querySelector('[data-mobile-menu]');
    this.mobileMenuCloseBtn = document.querySelector('[data-mobile-menu-close]');
    this.headerHeight = document.querySelector('header')?.offsetHeight || 0;
    this.lastScrollPosition = 0;

    this.initializeEventListeners();
    this.setupSmoothScrolling();
  }

  toggleMobileMenu(forceClose = false) {
    const isOpen = this.mobileMenu.classList.contains('translate-x-0');

    if (isOpen || forceClose) {
      gsap.to(this.mobileMenu, {
        x: '100%',
        duration: 0.2,
        ease: 'power1.in',
        onComplete: () => document.body.classList.remove('overflow-hidden'),
      });
    } else {
      gsap.to(this.mobileMenu, {
        x: 0,
        duration: 0.2,
        ease: 'power1.out',
        onStart: () => document.body.classList.add('overflow-hidden'),
      });

      gsap.from(this.mobileMenu.querySelectorAll('a'), {
        x: -20,
        opacity: 0,
        duration: 0.2,
        stagger: 0.05,
        ease: 'power1',
      });
    }
  }

  handleSmoothScroll(event, targetId) {
    event.preventDefault();

    // If it's a hash-only href (e.g., "#") or empty, don't scroll
    if (targetId === '#' || !targetId) {
      return;
    }

    const targetSection = document.querySelector(targetId);

    if (targetSection) {
      // Calculate offset considering fixed header and some padding

      this.toggleMobileMenu(true);

      gsap.to(window, {
        duration: 0.4,
        scrollTo: {
          y: targetSection,
          autoKill: false
        },
        ease: "power2.inOut",
        onComplete: () => {
          // Update URL without triggering scroll
          if (history.pushState && targetId !== '#') {
            history.pushState(null, null, targetId);
          }


        }
      });
    }
  }

  setupSmoothScrolling() {
    // Handle all navigation links with href starting with #
    const navLinks = document.querySelectorAll('a[href^="#"]');

    navLinks.forEach(link => {
      link.addEventListener('click', (e) => {
        const targetId = link.getAttribute('href');
        this.handleSmoothScroll(e, targetId);
      });

      // Add active state handling
      link.addEventListener('mouseenter', () => {
        link.classList.add('text-accent');
      });

      link.addEventListener('mouseleave', () => {
        if (!link.classList.contains('active')) {
          link.classList.remove('text-accent');
        }
      });
    });

    // Update active state on scroll
    window.addEventListener('scroll', () => {
      this.updateActiveNavLink();
    }, { passive: true });
  }

  updateActiveNavLink() {
    const sections = document.querySelectorAll('section[id]');
    const scrollPosition = window.scrollY + (window.innerHeight / 3);

    sections.forEach(section => {
      const sectionTop = section.offsetTop - this.headerHeight;
      const sectionHeight = section.offsetHeight;
      const sectionId = section.getAttribute('id');
      const correspondingLink = document.querySelector(`a[href="#${sectionId}"]`);

      if (correspondingLink) {
        if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
          document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('text-accent');
          });
          correspondingLink.classList.add('text-accent');
        }
      }
    });
  }

  initializeEventListeners() {
    this.mobileMenuBtn?.addEventListener('click', () => this.toggleMobileMenu());
    this.mobileMenuCloseBtn?.addEventListener('click', () => this.toggleMobileMenu(true));

    // Close menu on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.toggleMobileMenu(true);
      }
    });

    // Handle click outside mobile menu
    document.addEventListener('click', (e) => {
      if (this.mobileMenu?.classList.contains('translate-x-0') &&
        !this.mobileMenu.contains(e.target) &&
        !this.mobileMenuBtn.contains(e.target)) {
        this.toggleMobileMenu(true);
      }
    });
  }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
  gsap.registerPlugin(ScrollTrigger, ScrollToPlugin);
  lucide.createIcons();
  window.nav = new Navigation();

  // Initial animations (only if targets exist)
  if (document.querySelector('.min-h-screen > div > div > div:first-child > *')) {
    gsap.from('.min-h-screen > div > div > div:first-child > *', {
      y: 30,
      opacity: 0,
      duration: 0.4,
      stagger: 0.1,
      ease: 'power1',
    });
  }

  if (document.querySelector('.min-h-screen .floating')) {
    gsap.from('.min-h-screen .floating', {
      scale: 0.9,
      opacity: 0,
      duration: 0.5,
      delay: 0.2,
      ease: 'power1',
    });
  }

  if (document.querySelector('.min-h-screen .floating + .absolute')) {
    gsap.from('.min-h-screen .floating + .absolute', {
      x: -50,
      opacity: 0,
      duration: 0.5,
      delay: 0.3,
      ease: 'power1',
    });
  }

  if (document.querySelector('.main-nav a')) {
    gsap.from('.main-nav a', {
      opacity: 0,
      y: -10,
      duration: 0.2,
      stagger: 0.05,
      ease: 'power1',
    });
  }
});

document.addEventListener('DOMContentLoaded', () => {
  const marquee = document.getElementById('marquee');
  if (!marquee || !marquee.children || marquee.children.length === 0) {
    return;
  }
  let speed = 2; // Default speed in pixels per frame
  let isPaused = false;

  const firstChild = marquee.children[0].cloneNode(true);
  marquee.appendChild(firstChild);

  const totalWidth = Array.from(marquee.children).reduce((sum, child) => {
    return sum + child.offsetWidth;
  }, 0);

  marquee.style.width = `${totalWidth}px`;

  let offset = 0;

  const animate = () => {
    if (!isPaused) {
      offset -= speed;
      if (Math.abs(offset) >= firstChild.offsetWidth) {
        offset = 0;
      }
      marquee.style.transform = `translateX(${offset}px)`;
    }
    requestAnimationFrame(animate);
  };

  animate();


  const updateSpeed = () => {
    if (window.innerWidth < 768) {
      speed = 2.2;
    } else {
      speed = 1.4;
    }
  };

  window.addEventListener('resize', updateSpeed);
  updateSpeed();

  // marquee.addEventListener('mouseover', () => (isPaused = true));
  // marquee.addEventListener('mouseout', () => (isPaused = false));
});


// Reveal Text Animation
function animateRevealText() {
  const revealTexts = document.querySelectorAll('.reveal-text');

  revealTexts.forEach(text => {
    ScrollTrigger.create({
      trigger: text,
      start: "top 85%",
      onEnter: () => text.classList.add('revealed')
    });
  });
}