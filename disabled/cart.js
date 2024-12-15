class ShoppingCart {
  constructor() {

    this.availableItems = [
      {
        id: 'prod-1',
        name: 'Książka',
        price: 49.99,
        description: 'książka',
        image: 'https://placehold.co/80',
        inStock: true
      },
      {
        id: 'prod-2',
        name: 'Sesja mentoringowa',
        price: 499.99,
        description: 'mentoring',
        image: 'https://placehold.co/80',
        inStock: true
      },
      {
        id: 'prod-3',
        name: 'Szkolenie firmowe',
        price: 2299.99,
        description: 'szkolenie',
        image: 'https://placehold.co/80',
        inStock: false
      }
    ];

    this.items = JSON.parse(localStorage.getItem('shopping_cart') || '[]');
    this.total = 0;
    this.isOpen = false;
    this.isMobile = window.innerWidth < 768;
    this.cartMenuDesktop = document.querySelector('[data-cart-menu]');
    this.cartMenuMobile = document.querySelector('[data-cart-menu-mobile]');
    this.updateTotal();
    this.bindEvents();
    this.renderCartItems();
    this.renderAvailableItems();
  }

  bindEvents() {
    document.querySelectorAll('.shopping-cart-trigger').forEach(trigger => {
      trigger.addEventListener('click', () => this.toggleCartMenu());
    });

    window.addEventListener('resize', () => {
      this.isMobile = window.innerWidth < 768;
      if (this.isOpen) {
        this.toggleCartMenu(true);
      }
    });

    // Close cart when clicking outside
    document.addEventListener('click', (e) => {
      if (this.isOpen && 
          !this.cartMenuDesktop?.contains(e.target) && 
          !this.cartMenuMobile?.contains(e.target) && 
          !e.target.closest('.shopping-cart-trigger')) {
        this.toggleCartMenu();
      }
    });

    // Handle escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isOpen) {
        this.toggleCartMenu();
      }
    });
  }

  toggleCartMenu(forceClose = false) {
    this.isOpen = forceClose ? false : !this.isOpen;
    const menu = this.isMobile ? this.cartMenuMobile : this.cartMenuDesktop;
    
    if (this.isOpen) {
      document.body.classList.add('overflow-hidden');
      
      if (this.isMobile) {
        gsap.to(menu, { 
          yPercent: -100, 
          duration: 0.15,
          ease: 'power1'
        });
      } else {
        gsap.to(menu, { 
          xPercent: -100, 
          duration: 0.15,
          ease: 'power1'
        });
      }
      
      // Animate cart items
      gsap.from(menu.querySelectorAll('.cart-item'), {
        x: this.isMobile ? 0 : 50,
        y: this.isMobile ? 50 : 0,
        opacity: 0,
        duration: 0.15,
        stagger: 0.05,
        delay: 0.1
      });
    } else {
      if (this.isMobile) {
        gsap.to(menu, { 
          yPercent: 100,
          duration: 0.15,
          ease: 'power1',
          onComplete: () => document.body.classList.remove('overflow-hidden')
        });
      } else {
        gsap.to(menu, { 
          xPercent: 0,
          duration: 0.15,
          ease: 'power1',
          onComplete: () => document.body.classList.remove('overflow-hidden')
        });
      }
    }
  }

  addItem(productId) {
    const product = this.availableItems.find(item => item.id === productId);
    if (!product) return;
    if (!product.inStock) {
      alert('Przepraszamy, produkt jest obecnie niedostępny.');
      return;
    }

    this.items.push({
      ...product,
      cartId: Date.now().toString() // Unique ID for cart item
    });
    
    this.updateTotal();
    this.saveCart();
    this.renderCartItems();

    // Show feedback animation
    const cartIcon = document.querySelector('.shopping-cart-trigger i');
    gsap.from(cartIcon, {
      scale: 1.4,
      duration: 0.2,
      ease: 'back.out'
    });
  }

  removeItem(cartId) {
    const itemElement = this.cartMenuDesktop.querySelector(`[data-item-id="${cartId}"]`);
    const itemElementMobile = this.cartMenuMobile.querySelector(`[data-item-id="${cartId}"]`);
    
    const removeAnimation = (element) => {
      gsap.to(element, {
        height: 0,
        opacity: 0,
        duration: 0.2,
        ease: 'power1',
        onComplete: () => {
          this.items = this.items.filter(item => item.cartId !== cartId);
          this.updateTotal();
          this.saveCart();
          this.renderCartItems();
        }
      });
    };

    if (itemElement) removeAnimation(itemElement);
    if (itemElementMobile) removeAnimation(itemElementMobile);
  }

  renderAvailableItems() {
    const productsContainer = document.querySelector('[data-products-container]');
    if (!productsContainer) return;

    productsContainer.innerHTML = this.availableItems.map(product => `
      <div class="product-card p-4 border rounded-lg shadow-sm">
        <img 
          src="${product.image}" 
          alt="${product.name}" 
          class="w-full h-32 object-cover rounded-md mb-4"
        >
        <h3 class="font-medium text-lg mb-2">${product.name}</h3>
        <p class="text-gray-600 text-sm mb-3">${product.description}</p>
        <div class="flex justify-between items-center">
          <span class="font-bold">${product.price} PLN</span>
          <button 
            onclick="window.nav.cart.addItem('${product.id}')"
            class="px-4 py-2 bg-accent text-white rounded-md hover:bg-accent/90 transition-colors duration-200 ${!product.inStock ? 'opacity-50 cursor-not-allowed' : ''}"
            ${!product.inStock ? 'disabled' : ''}
          >
            ${product.inStock ? 'Dodaj do koszyka' : 'Niedostępny'}
          </button>
        </div>
      </div>
    `).join('');
  }

  renderCartItems() {
    const renderToMenu = (menu) => {
      if (!menu) return;
      
      const cartItemsContainer = menu.querySelector('.cart-items');
      
      if (this.items.length === 0) {
        cartItemsContainer.innerHTML = `
          <div class="flex flex-col items-center justify-center h-full text-gray-500">
            <i data-lucide="shopping-cart" class="h-12 w-12 mb-4"></i>
            <p class="text-center">Twój koszyk jest pusty</p>
          </div>
        `;
      } else {
        cartItemsContainer.innerHTML = this.items.map(item => `
          <div class="cart-item flex justify-between items-center p-4 border-b border-gray-100" data-item-id="${item.cartId}">
            <div class="flex items-center">
              <img 
                src="${item.image}" 
                alt="${item.name}" 
                class="w-16 h-16 object-cover rounded-md mr-4"
              >
              <div>
                <h4 class="font-medium">${item.name}</h4>
                <p class="text-sm text-gray-600">${item.price} PLN</p>
              </div>
            </div>
            <button 
              onclick="window.nav.cart.removeItem('${item.cartId}')" 
              class="text-red-500 hover:text-red-700 transition-colors duration-200"
              aria-label="Usuń produkt"
            >
              <i data-lucide="trash-2" class="h-5 w-5"></i>
            </button>
          </div>
        `).join('');
      }
    };

    renderToMenu(this.cartMenuDesktop);
    renderToMenu(this.cartMenuMobile);
    lucide.createIcons();
  }

  updateTotal() {
    this.total = this.items.reduce((sum, item) => sum + item.price, 0);
    document.querySelectorAll('.shopping-cart-amount').forEach(el => {
      el.textContent = `${this.total.toFixed(2)} PLN`;
    });

    // Update cart icon badge if items exist
    document.querySelectorAll('.shopping-cart-trigger').forEach(trigger => {
      const badge = trigger.querySelector('.cart-badge');
      if (this.items.length > 0) {
        if (!badge) {
          const newBadge = document.createElement('span');
          newBadge.className = 'cart-badge absolute -top-2 -right-2 bg-accent text-white text-xs rounded-full h-5 w-5 flex items-center justify-center';
          newBadge.textContent = this.items.length;
          trigger.style.position = 'relative';
          trigger.appendChild(newBadge);
        } else {
          badge.textContent = this.items.length;
        }
      } else if (badge) {
        badge.remove();
      }
    });
  }

  saveCart() {
    localStorage.setItem('shopping_cart', JSON.stringify(this.items));
  }
}

class Navigation {
  constructor() {
    this.mainNav = document.querySelector('.main-nav');
    this.mobileMenuBtn = document.querySelector('[data-mobile-menu-btn]');
    this.mobileMenu = document.querySelector('[data-mobile-menu]');
    this.mobileMenuCloseBtn = document.querySelector('[data-mobile-menu-close]');
    this.lastScrollPosition = 0;
    this.cart = new ShoppingCart();
    
    this.initializeEventListeners();
    this.setupNavigationLinks();
  }

  toggleMobileMenu(forceClose = false) {
    const isOpen = this.mobileMenu.classList.contains('translate-x-0');
    
    if (isOpen || forceClose) {
      gsap.to(this.mobileMenu, { 
        x: '100%', 
        duration: 0.2, 
        ease: 'power1.in',
        onComplete: () => document.body.classList.remove('overflow-hidden')
      });
    } else {
      gsap.to(this.mobileMenu, { 
        x: 0, 
        duration: 0.2, 
        ease: 'power1.out',
        onStart: () => document.body.classList.add('overflow-hidden')
      });

      gsap.from(this.mobileMenu.querySelectorAll('a'), {
        x: -20,
        opacity: 0,
        duration: 0.2,
        stagger: 0.05,
        ease: 'power1'
      });
    }
  }

  handleSectionScroll(targetId) {
    const targetSection = document.querySelector(targetId);
    if (targetSection && scroll) {
      scroll.scrollTo(targetSection);
      this.toggleMobileMenu(true);
      if (this.cart.isOpen) {
        this.cart.toggleCartMenu(true);
      }
    }
  }

  setupNavigationLinks() {
    document.querySelectorAll('a[href^="#"]').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = link.getAttribute('href');
        this.handleSectionScroll(targetId);
      });
    });
  }
  

  initializeEventListeners() {
    this.mobileMenuBtn?.addEventListener('click', () => this.toggleMobileMenu());
    this.mobileMenuCloseBtn?.addEventListener('click', () => this.toggleMobileMenu(true));

    // Close menus on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.toggleMobileMenu(true);
        if (this.cart.isOpen) {
          this.cart.toggleCartMenu();
        }
      }
    });
  }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
  gsap.registerPlugin(ScrollTrigger);
  lucide.createIcons();
  window.nav = new Navigation();

  // Initial animations
  gsap.from('.min-h-screen > div > div > div:first-child > *', {
    y: 30,
    opacity: 0,
    duration: 0.4,
    stagger: 0.1,
    ease: 'power1'
  });

  gsap.from('.min-h-screen .floating', {
    scale: 0.9,
    opacity: 0,
    duration: 0.5,
    delay: 0.2,
    ease: 'power1'
  });

  gsap.from('.min-h-screen .floating + .absolute', {
    x: -50,
    opacity: 0,
    duration: 0.5,
    delay: 0.3,
    ease: 'power1'
  });

  gsap.from('.main-nav a', {
    opacity: 0,
    y: -10,
    duration: 0.2,
    stagger: 0.05,
    ease: 'power1'
  });
});

// Initialize smooth scroll
let scroll;

const initScroll = () => {
  scroll = new LocomotiveScroll({
    el: document.querySelector(".smooth-scroll"),
    smooth: true,
    smartphone: { smooth: true },
    tablet: { smooth: true }
  });

};

window.addEventListener("load", initScroll);