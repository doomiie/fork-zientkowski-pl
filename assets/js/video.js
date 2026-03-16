(function () {
  "use strict";

  var API_URL = "/backend/video.php";
  var AUTH_API_URL = "/backend/video_auth.php";
  var ACCESS_API_URL = "/backend/access_token.php";
  var AUTHOR_STORAGE_KEY = "video_comment_author";
  var AUTHOR_COOKIE_KEY = "video_comment_author";
  var params = new URLSearchParams(window.location.search);
  var source = (params.get("source") || "").trim();
  var accessToken = (params.get("vt") || "").trim();
  var rawEdit = (params.get("edit") || "").trim().toLowerCase();
  var editMode = rawEdit === "1" || rawEdit === "true" || rawEdit === "yes" || rawEdit === "on";

  var titleEl = document.getElementById("video-title");
  var statusEl = document.getElementById("video-status");
  var tokenInfoEl = document.getElementById("video-token-info");
  var accessMessageEl = document.getElementById("video-access-message");
  var playerWrapEl = document.getElementById("video-player-wrap");
  var commentsSectionEl = document.getElementById("video-comments-section");
  var navDesktopEl = document.getElementById("navDesktop");
  var navMobileEl = document.getElementById("navMobile");
  var videoMenuDesktopSlotEl = document.getElementById("video-menu-controls-desktop");
  var videoMenuMobileSlotEl = document.getElementById("video-menu-controls-mobile");
  var commentsListEl = document.getElementById("comments-list");
  var commentsEmptyEl = document.getElementById("comments-empty");
  var addCommentBtn = document.getElementById("add-comment-btn");
  var commentModalEl = document.getElementById("comment-modal");
  var commentModalOverlayEl = document.getElementById("comment-modal-overlay");
  var commentModalPanelEl = document.getElementById("comment-modal-panel");
  var commentModalCloseBtn = document.getElementById("comment-modal-close-btn");
  var formEl = document.getElementById("comment-form");
  var formStatusEl = document.getElementById("form-status");
  var cancelBtn = document.getElementById("cancel-comment-btn");
  var timeInput = document.getElementById("comment-time");
  var timeTextInput = document.getElementById("comment-time-text");
  var commentTimeFieldsEl = document.getElementById("comment-time-fields");
  var commentTitleFieldEl = document.getElementById("comment-title-field");
  var commentMetaFieldsEl = document.getElementById("comment-meta-fields");
  var commentMetaReadonlyEl = document.getElementById("comment-meta-readonly");
  var commentMetaTimeEl = document.getElementById("comment-meta-time");
  var commentMetaAuthorEl = document.getElementById("comment-meta-author");
  var titleInput = document.getElementById("comment-title");
  var contentInput = document.getElementById("comment-content");
  var transcribeBtn = document.getElementById("comment-transcribe-btn");
  var transcribeStatusEl = document.getElementById("comment-transcribe-status");
  var variantInput = document.getElementById("comment-variant");
  var authorInput = document.getElementById("comment-author");
  var commentIdInput = document.getElementById("comment-id");

  var authSectionEl = document.getElementById("video-auth-section");
  var authUserEl = document.getElementById("video-auth-user");
  var authFormEl = document.getElementById("video-auth-form");
  var authEmailEl = document.getElementById("video-auth-email");
  var authPasswordEl = document.getElementById("video-auth-password");
  var authCsrfEl = document.getElementById("video-auth-csrf");
  var authStatusEl = document.getElementById("video-auth-status");
  var authLogoutBtn = document.getElementById("video-auth-logout-btn");
  var authModalEl = document.getElementById("video-auth-modal");
  var authModalOverlayEl = document.getElementById("video-auth-modal-overlay");
  var authCloseBtn = document.getElementById("video-auth-close-btn");
  var addModalEl = document.getElementById("video-add-modal");
  var addModalOverlayEl = document.getElementById("video-add-modal-overlay");
  var addCloseBtn = document.getElementById("video-add-close-btn");

  var videoAddFormEl = document.getElementById("video-add-form");
  var videoAddInputEl = document.getElementById("video-add-input");
  var videoAddStatusEl = document.getElementById("video-add-status");

  var comments = [];
  var videos = [];
  var player = null;
  var playerReady = false;
  var lastPlayerState = -1;
  var pauseAutoOpenTimer = null;
  var pauseAutoOpenCandidate = null;
  var editingCommentId = null;
  var accessInfo = null;
  var hasContentAccess = false;
  var authState = { logged_in: false, user_id: null, email: null, role: null };
  var csrfToken = "";
  var ytApiRequested = false;
  var authMenuTrigger = null;
  var addMenuTrigger = null;
  var videoMenuTrigger = null;
  var videoMenuPanel = null;
  var videoMenuPickerDesktop = null;
  var videoMenuPickerMobile = null;
  var videoPickerModalEl = null;
  var videoPickerOverlayEl = null;
  var videoPickerCloseEl = null;
  var commentMenuTrigger = null;
  var commentPreviouslyFocusedEl = null;
  var previouslyFocusedEl = null;
  var closeMobileMenuFn = null;

  var SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var speechRecognition = null;
  var isTranscribing = false;
  var mediaStream = null;
  var transcribeHeardSpeech = false;
  var transcribeSilenceTimer = null;

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
  }
  function setVideoListStatus(msg) {
    var statusEls = document.querySelectorAll("[data-video-list-status]");
    statusEls.forEach(function (node) { node.textContent = msg || ""; });
  }
  function setTokenInfo(msg) {
    if (tokenInfoEl) tokenInfoEl.textContent = msg || "";
  }
  function setAuthStatus(msg) {
    if (authStatusEl) authStatusEl.textContent = msg || "";
  }
  function setVideoAddStatus(msg) {
    if (videoAddStatusEl) videoAddStatusEl.textContent = msg || "";
  }
  function setTranscribeStatus(msg) {
    if (transcribeStatusEl) transcribeStatusEl.textContent = msg || "";
  }

  function getVideoListSelectElements() {
    return Array.prototype.slice.call(document.querySelectorAll("[data-video-list-select]"));
  }

  function setVideoListValue(value) {
    getVideoListSelectElements().forEach(function (selectEl) {
      selectEl.value = value || "";
    });
  }

  function bindVideoListSelectHandlers() {
    getVideoListSelectElements().forEach(function (selectEl) {
      if (selectEl.dataset.boundVideoSelect === "1") return;
      selectEl.addEventListener("change", handleVideoSelectChange);
      selectEl.dataset.boundVideoSelect = "1";
    });
  }

  function isYoutubeId(value) {
    return /^[A-Za-z0-9_-]{6,20}$/.test(String(value || "").trim());
  }

  function buildVideoUrl(youtubeId) {
    var next = new URLSearchParams(window.location.search);
    next.set("source", youtubeId);
    if (editMode) next.set("edit", "1");
    else next.delete("edit");
    next.delete("vt");
    return "video.html?" + next.toString();
  }

  function resolveVideoTitle(video) {
    var dbTitle = String(video && video.tytul || "").trim();
    return dbTitle || "Bez tytułu";
  }

  function getStoredAuthor() {
    try {
      var cookieValue = String(getCookie(AUTHOR_COOKIE_KEY) || "").trim();
      if (cookieValue) return cookieValue;
      return String(window.localStorage.getItem(AUTHOR_STORAGE_KEY) || "").trim();
    } catch (error) { return ""; }
  }
  function setStoredAuthor(value) {
    try {
      var normalized = String(value || "").trim();
      if (normalized) {
        window.localStorage.setItem(AUTHOR_STORAGE_KEY, normalized);
        setCookie(AUTHOR_COOKIE_KEY, normalized, 365);
      } else {
        window.localStorage.removeItem(AUTHOR_STORAGE_KEY);
        setCookie(AUTHOR_COOKIE_KEY, "", -1);
      }
    } catch (error) { }
  }

  function getCookie(name) {
    var key = String(name || "").trim();
    if (!key || !document || !document.cookie) return "";
    var escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    var match = document.cookie.match(new RegExp("(?:^|; )" + escaped + "=([^;]*)"));
    return match ? decodeURIComponent(match[1] || "") : "";
  }

  function setCookie(name, value, days) {
    var key = String(name || "").trim();
    if (!key || !document) return;
    var ttl = Number(days || 0);
    var expires = "";
    if (ttl) {
      var date = new Date();
      date.setTime(date.getTime() + (ttl * 24 * 60 * 60 * 1000));
      expires = "; expires=" + date.toUTCString();
    }
    document.cookie = key + "=" + encodeURIComponent(String(value || "")) + expires + "; path=/; SameSite=Lax";
  }

  function resolveDefaultAuthor(access) {
    var persisted = getStoredAuthor();
    if (persisted) return persisted;
    if (authState && authState.logged_in) {
      var email = String(authState.email || "").trim();
      if (email) return email;
      return "zalogowany user";
    }
    if (hasTokenSession(access || accessInfo)) return "anonim";
    return "";
  }

  function applyDefaultAuthor(access) {
    if (!authorInput) return;
    var current = String(authorInput.value || "").trim();
    if (current) return;
    var fallback = resolveDefaultAuthor(access);
    if (!fallback) return;
    authorInput.value = fallback;
  }

  function ensureReadonlyAuthor() {
    if (!authorInput) return "";
    var current = String(authorInput.value || "").trim();
    if (current) return current;
    var fallback = resolveDefaultAuthor(accessInfo) || "anonim";
    authorInput.value = fallback;
    return fallback;
  }

  function refreshReadonlyCommentMeta() {
    if (commentMetaTimeEl) {
      var timeLabel = String(timeTextInput && timeTextInput.value || "").trim();
      if (!timeLabel && timeInput) {
        timeLabel = formatTime(Math.max(0, Number(timeInput.value || "0") || 0));
      }
      commentMetaTimeEl.textContent = timeLabel || "00:00";
    }
    if (commentMetaAuthorEl) {
      var authorLabel = ensureReadonlyAuthor();
      commentMetaAuthorEl.textContent = authorLabel || "-";
    }
  }

  function setCommentFormMode(isEditing) {
    var editing = !!isEditing;
    if (commentTimeFieldsEl) commentTimeFieldsEl.hidden = !editing;
    if (commentTitleFieldEl) commentTitleFieldEl.hidden = !editing;
    if (commentMetaFieldsEl) commentMetaFieldsEl.hidden = !editing;
    if (commentMetaReadonlyEl) {
      commentMetaReadonlyEl.hidden = editing;
      if (!editing) refreshReadonlyCommentMeta();
    }
  }

  function isCommentModalOpen() {
    return !!(commentModalEl && commentModalEl.hidden === false);
  }

  function anyPrimaryModalOpen() {
    var authOpen = !!(authModalEl && authModalEl.hidden === false);
    var addOpen = !!(addModalEl && addModalEl.hidden === false);
    var pickerOpen = !!(videoPickerModalEl && videoPickerModalEl.hidden === false);
    var commentOpen = isCommentModalOpen();
    return authOpen || addOpen || pickerOpen || commentOpen;
  }

  function openCommentModal(triggerEl) {
    if (!commentModalEl) return false;
    if (isCommentModalOpen()) return true;
    if (videoPickerModalEl && videoPickerModalEl.hidden === false) closeDesktopVideoMenuPanel(false);
    if (authModalEl && authModalEl.hidden === false) closeAuthModal();
    if (addModalEl && addModalEl.hidden === false) closeAddModal();
    commentMenuTrigger = triggerEl || null;
    commentPreviouslyFocusedEl = document.activeElement;
    commentModalEl.hidden = false;
    document.body.classList.add("video-modal-open");
    return true;
  }

  function closeCommentModal(restoreFocus) {
    if (!commentModalEl) return;
    commentModalEl.hidden = true;
    if (!anyPrimaryModalOpen()) document.body.classList.remove("video-modal-open");
    if (restoreFocus) {
      var focusTarget = commentMenuTrigger || commentPreviouslyFocusedEl;
      if (focusTarget && typeof focusTarget.focus === "function") {
        focusTarget.focus();
      }
    }
    commentMenuTrigger = null;
    commentPreviouslyFocusedEl = null;
  }

  async function exchangeAccessToken(token) {
    var response = await fetch(ACCESS_API_URL + "?action=exchange", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ token: String(token || "").trim(), target: "video" })
    });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Token dostępu jest niepoprawny.");
    return data;
  }

  function removeAccessTokenFromUrl() {
    var next = new URLSearchParams(window.location.search);
    next.delete("vt");
    var query = next.toString();
    window.history.replaceState({}, "", "video.html" + (query ? ("?" + query) : ""));
  }

  function syncAuthFromAccess(access) {
    if (!access || !access.user) return;
    authState = {
      logged_in: !!access.user.logged_in,
      user_id: access.user.user_id || null,
      email: access.user.email || null,
      role: access.user.role || null
    };
  }

  function roleLabel(role) {
    if (role === "admin") return "admin";
    if (role === "trener") return "trener";
    if (role === "user") return "user";
    return "-";
  }

  function hasTokenSession(access) {
    return !!(access && access.token && access.token.token_id);
  }

  function computeContentAccess(access) {
    return !!authState.logged_in || hasTokenSession(access || accessInfo);
  }

  function applyAccessState(allowed) {
    hasContentAccess = !!allowed;
    document.body.classList.toggle("video-has-content-access", hasContentAccess);
    if (videoMenuPickerDesktop) videoMenuPickerDesktop.hidden = !hasContentAccess;
    if (videoMenuPickerMobile) videoMenuPickerMobile.hidden = !hasContentAccess;
    if (!hasContentAccess) closeDesktopVideoMenuPanel(false);
    if (playerWrapEl) playerWrapEl.hidden = !hasContentAccess;
    if (commentsSectionEl) commentsSectionEl.hidden = !hasContentAccess;
    if (accessMessageEl) accessMessageEl.hidden = hasContentAccess;
    if (!hasContentAccess) {
      comments = [];
      renderComments();
      getVideoListSelectElements().forEach(function (selectEl) {
        selectEl.innerHTML = "<option value=\"\">Brak dostępu</option>";
      });
      if (addCommentBtn) addCommentBtn.hidden = true;
      hideForm(false);
    }
  }

  function updateAuthMenuButtons() {
    var desktopBtn = document.getElementById("video-auth-trigger-desktop");
    var mobileBtn = document.getElementById("video-auth-trigger-mobile");
    var label = authState.logged_in ? "Konto" : "Logowanie";
    if (desktopBtn) desktopBtn.textContent = label;
    if (mobileBtn) mobileBtn.textContent = label;
  }

  function openAuthModalFromMenu(event) {
    authMenuTrigger = event && event.currentTarget ? event.currentTarget : null;
    closeDesktopVideoMenuPanel(false);
    if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
    openAuthModal();
  }

  function openAddModalFromMenu(event) {
    if (!canCurrentUserAddVideo()) return;
    addMenuTrigger = event && event.currentTarget ? event.currentTarget : null;
    closeDesktopVideoMenuPanel(false);
    if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
    openAddModal();
  }

  function updateVideoAddMenuButtons() {
    var desktopBtn = document.getElementById("video-add-trigger-desktop");
    var mobileBtn = document.getElementById("video-add-trigger-mobile");
    var canAdd = canCurrentUserAddVideo();
    if (desktopBtn) desktopBtn.hidden = !canAdd;
    if (mobileBtn) mobileBtn.hidden = !canAdd;
  }

  function canCurrentUserAddVideo() {
    return !!(
      authState &&
      authState.logged_in === true &&
      accessInfo &&
      accessInfo.effective &&
      accessInfo.effective.can_add_video === true
    );
  }

  function buildDesktopVideoMenuPicker(root) {
    if (!root) return;
    var picker = document.getElementById("video-picker-desktop");
    if (!picker) {
      picker = document.createElement("div");
      picker.id = "video-picker-desktop";
      picker.className = "video-menu-picker";
      picker.hidden = true;

      var trigger = document.createElement("button");
      trigger.id = "video-picker-trigger-desktop";
      trigger.type = "button";
      trigger.className = "video-menu-btn video-menu-btn--picker";
      trigger.setAttribute("aria-haspopup", "dialog");
      trigger.setAttribute("aria-expanded", "false");
      trigger.setAttribute("aria-controls", "video-picker-modal-panel");
      trigger.textContent = "Filmy";
      picker.appendChild(trigger);
      root.appendChild(picker);
    }
    videoMenuPickerDesktop = picker;
  }

  function buildMobileVideoMenuPicker(root) {
    if (!root) return;
    var picker = document.getElementById("video-picker-mobile");
    if (!picker) {
      picker = document.createElement("div");
      picker.id = "video-picker-mobile";
      picker.className = "video-mobile-picker-trigger";
      picker.hidden = true;

      var trigger = document.createElement("button");
      trigger.id = "video-picker-trigger-mobile";
      trigger.type = "button";
      trigger.className = "video-mobile-nav-btn";
      trigger.setAttribute("aria-haspopup", "dialog");
      trigger.setAttribute("aria-expanded", "false");
      trigger.setAttribute("aria-controls", "video-picker-modal-panel");
      trigger.textContent = "Filmy";
      picker.appendChild(trigger);
      root.appendChild(picker);
    }
    videoMenuPickerMobile = picker;
  }

  function ensureVideoPickerModal() {
    if (videoPickerModalEl) return;
    var modal = document.createElement("div");
    modal.id = "video-picker-modal";
    modal.className = "video-picker-modal";
    modal.hidden = true;

    var overlay = document.createElement("div");
    overlay.id = "video-picker-modal-overlay";
    overlay.className = "video-picker-overlay";

    var panel = document.createElement("section");
    panel.id = "video-picker-modal-panel";
    panel.className = "video-picker-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    panel.setAttribute("aria-labelledby", "video-picker-modal-title");

    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.id = "video-picker-close-btn";
    closeBtn.className = "video-picker-panel__close";
    closeBtn.setAttribute("aria-label", "Zamknij wybór filmu");
    closeBtn.textContent = "×";

    var heading = document.createElement("h2");
    heading.id = "video-picker-modal-title";
    heading.className = "video-picker-panel__title";
    heading.textContent = "Wybierz film";

    var label = document.createElement("label");
    label.className = "video-picker-panel__label";
    label.setAttribute("for", "video-list-select-modal");
    label.textContent = "Lista filmów";

    var select = document.createElement("select");
    select.id = "video-list-select-modal";
    select.name = "video_list_modal";
    select.className = "video-picker-panel__select";
    select.setAttribute("data-video-list-select", "modal");
    select.innerHTML = "<option value=\"\">Ładowanie listy...</option>";

    var status = document.createElement("p");
    status.id = "video-list-status-modal";
    status.className = "video-picker-panel__status";
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    status.setAttribute("data-video-list-status", "modal");

    label.appendChild(select);
    panel.appendChild(closeBtn);
    panel.appendChild(heading);
    panel.appendChild(label);
    panel.appendChild(status);
    modal.appendChild(overlay);
    modal.appendChild(panel);
    document.body.appendChild(modal);

    videoPickerModalEl = modal;
    videoPickerOverlayEl = overlay;
    videoPickerCloseEl = closeBtn;
    videoMenuPanel = panel;

    overlay.addEventListener("click", function () { closeDesktopVideoMenuPanel(true); });
    closeBtn.addEventListener("click", function () { closeDesktopVideoMenuPanel(true); });
  }

  function closeDesktopVideoMenuPanel(shouldRestoreFocus) {
    if (videoPickerModalEl) videoPickerModalEl.hidden = true;
    var desktopTrigger = document.getElementById("video-picker-trigger-desktop");
    var mobileTrigger = document.getElementById("video-picker-trigger-mobile");
    if (desktopTrigger) desktopTrigger.setAttribute("aria-expanded", "false");
    if (mobileTrigger) mobileTrigger.setAttribute("aria-expanded", "false");
    if (shouldRestoreFocus && typeof videoMenuTrigger.focus === "function") {
      videoMenuTrigger.focus();
    }
  }

  function openDesktopVideoMenuPanel() {
    if (!hasContentAccess) return;
    ensureVideoPickerModal();
    bindVideoListSelectHandlers();
    if (videoPickerModalEl) videoPickerModalEl.hidden = false;
    if (videoMenuTrigger) videoMenuTrigger.setAttribute("aria-expanded", "true");
    var select = document.getElementById("video-list-select-modal");
    if (select) select.focus();
  }

  function openVideoPickerFromMenu(event) {
    if (event) {
      event.preventDefault();
      videoMenuTrigger = event.currentTarget || null;
    }
    if (videoPickerModalEl && !videoPickerModalEl.hidden) {
      closeDesktopVideoMenuPanel(false);
      return;
    }
    openDesktopVideoMenuPanel();
  }

  function handleDesktopVideoMenuGlobalPointer(event) {
    if (!videoPickerModalEl || videoPickerModalEl.hidden || !videoMenuPanel) return;
    if (videoMenuPanel.contains(event.target)) return;
    if (videoPickerOverlayEl && videoPickerOverlayEl.contains(event.target)) return;
    closeDesktopVideoMenuPanel(false);
  }

  function handleDesktopVideoMenuGlobalKeydown(event) {
    if (event.key !== "Escape") return;
    if (!videoPickerModalEl || videoPickerModalEl.hidden) return;
    event.preventDefault();
    closeDesktopVideoMenuPanel(true);
  }

  function ensureVideoMenuButtons() {
    var navDesktop = navDesktopEl;
    var navMobile = navMobileEl;
    if (!navDesktop || !navMobile) return false;
    var desktopRoot = videoMenuDesktopSlotEl || navDesktop;
    var mobileRoot = videoMenuMobileSlotEl || navMobile;

    buildDesktopVideoMenuPicker(desktopRoot);
    buildMobileVideoMenuPicker(mobileRoot);
    bindVideoListSelectHandlers();
    if (videos.length || source) renderVideoList();
    if (!hasContentAccess) {
      getVideoListSelectElements().forEach(function (selectEl) {
        selectEl.innerHTML = "<option value=\"\">Brak dostępu</option>";
      });
    }

    ensureVideoPickerModal();
    bindVideoListSelectHandlers();

    var desktopPickerBtn = document.getElementById("video-picker-trigger-desktop");
    var mobilePickerBtn = document.getElementById("video-picker-trigger-mobile");
    if (desktopPickerBtn && desktopPickerBtn.dataset.boundToggle !== "1") {
      desktopPickerBtn.addEventListener("click", openVideoPickerFromMenu);
      desktopPickerBtn.dataset.boundToggle = "1";
    }
    if (mobilePickerBtn && mobilePickerBtn.dataset.boundToggle !== "1") {
      mobilePickerBtn.addEventListener("click", openVideoPickerFromMenu);
      mobilePickerBtn.dataset.boundToggle = "1";
    }

    var desktopBtn = document.getElementById("video-auth-trigger-desktop");
    if (!desktopBtn) {
      desktopBtn = document.createElement("button");
      desktopBtn.id = "video-auth-trigger-desktop";
      desktopBtn.type = "button";
      desktopBtn.className = "video-menu-btn video-menu-btn--secondary";
      desktopBtn.textContent = "Logowanie";
      desktopBtn.addEventListener("click", openAuthModalFromMenu);
      desktopRoot.appendChild(desktopBtn);
    }

    var mobileBtn = document.getElementById("video-auth-trigger-mobile");
    if (!mobileBtn) {
      mobileBtn = document.createElement("button");
      mobileBtn.id = "video-auth-trigger-mobile";
      mobileBtn.type = "button";
      mobileBtn.className = "video-mobile-nav-btn";
      mobileBtn.textContent = "Logowanie";
      mobileBtn.addEventListener("click", openAuthModalFromMenu);
      mobileRoot.appendChild(mobileBtn);
    }

    var desktopAddBtn = document.getElementById("video-add-trigger-desktop");
    if (!desktopAddBtn) {
      desktopAddBtn = document.createElement("button");
      desktopAddBtn.id = "video-add-trigger-desktop";
      desktopAddBtn.type = "button";
      desktopAddBtn.className = "video-menu-btn video-menu-btn--secondary";
      desktopAddBtn.textContent = "Dodaj video";
      desktopAddBtn.hidden = true;
      desktopAddBtn.addEventListener("click", openAddModalFromMenu);
      desktopRoot.appendChild(desktopAddBtn);
    }

    var mobileAddBtn = document.getElementById("video-add-trigger-mobile");
    if (!mobileAddBtn) {
      mobileAddBtn = document.createElement("button");
      mobileAddBtn.id = "video-add-trigger-mobile";
      mobileAddBtn.type = "button";
      mobileAddBtn.className = "video-mobile-nav-btn";
      mobileAddBtn.textContent = "Dodaj video";
      mobileAddBtn.hidden = true;
      mobileAddBtn.addEventListener("click", openAddModalFromMenu);
      mobileRoot.appendChild(mobileAddBtn);
    }

    updateAuthMenuButtons();
    updateVideoAddMenuButtons();
    return true;
  }

  function scheduleVideoMenuButtons() {
    if (ensureVideoMenuButtons()) return;
    var retries = 0;
    var timer = window.setInterval(function () {
      retries += 1;
      if (ensureVideoMenuButtons() || retries > 20) window.clearInterval(timer);
    }, 250);
  }

  function getFocusableWithin(node) {
    if (!node) return [];
    return Array.prototype.slice.call(
      node.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')
    );
  }

  function openAuthModal() {
    if (!authModalEl) return;
    if (addModalEl && !addModalEl.hidden) closeAddModal();
    previouslyFocusedEl = document.activeElement;
    authModalEl.hidden = false;
    document.body.classList.add("video-modal-open");
    var target = (!authState.logged_in && authEmailEl) ? authEmailEl : (authCloseBtn || authPasswordEl || authEmailEl);
    if (target) target.focus();
  }

  function closeAuthModal() {
    if (!authModalEl) return;
    authModalEl.hidden = true;
    document.body.classList.remove("video-modal-open");
    if (authMenuTrigger && typeof authMenuTrigger.focus === "function") {
      authMenuTrigger.focus();
      authMenuTrigger = null;
      return;
    }
    if (previouslyFocusedEl && typeof previouslyFocusedEl.focus === "function") {
      previouslyFocusedEl.focus();
    }
    previouslyFocusedEl = null;
  }

  function openAddModal() {
    if (!addModalEl) return;
    if (authModalEl && !authModalEl.hidden) closeAuthModal();
    setVideoAddStatus("");
    previouslyFocusedEl = document.activeElement;
    addModalEl.hidden = false;
    document.body.classList.add("video-modal-open");
    if (videoAddInputEl) videoAddInputEl.focus();
  }

  function closeAddModal() {
    if (!addModalEl) return;
    addModalEl.hidden = true;
    document.body.classList.remove("video-modal-open");
    if (addMenuTrigger && typeof addMenuTrigger.focus === "function") {
      addMenuTrigger.focus();
      addMenuTrigger = null;
      return;
    }
    if (previouslyFocusedEl && typeof previouslyFocusedEl.focus === "function") {
      previouslyFocusedEl.focus();
    }
    previouslyFocusedEl = null;
  }

  function handleAuthModalKeydown(event) {
    if (!authModalEl || authModalEl.hidden) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeAuthModal();
      return;
    }
    if (event.key !== "Tab") return;
    var focusables = getFocusableWithin(authModalEl);
    if (!focusables.length) return;
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function handleAddModalKeydown(event) {
    if (!addModalEl || addModalEl.hidden) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeAddModal();
      return;
    }
    if (event.key !== "Tab") return;
    var focusables = getFocusableWithin(addModalEl);
    if (!focusables.length) return;
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function handleCommentModalKeydown(event) {
    if (!isCommentModalOpen()) return;
    if (event.key === "Escape") {
      event.preventDefault();
      hideForm(true);
      return;
    }
    if (event.key !== "Tab") return;
    var focusables = getFocusableWithin(commentModalPanelEl || commentModalEl);
    if (!focusables.length) return;
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function initMobileMenuHandlers() {
    var openBtn = document.getElementById("menuToggleHome");
    var closeBtn = document.getElementById("menuCloseHome");
    var overlay = document.getElementById("mobileMenuOverlayHome");
    var menu = document.getElementById("mobileMenuHome");

    function openMenu() {
      if (!overlay || !menu) return;
      if (openBtn) openBtn.setAttribute("aria-expanded", "true");
      closeDesktopVideoMenuPanel(false);
      overlay.classList.remove("hidden");
      menu.classList.remove("translate-x-full");
    }

    function closeMenu() {
      if (!overlay || !menu) return;
      if (openBtn) openBtn.setAttribute("aria-expanded", "false");
      overlay.classList.add("hidden");
      menu.classList.add("translate-x-full");
    }
    closeMobileMenuFn = closeMenu;

    if (openBtn) openBtn.addEventListener("click", openMenu);
    if (closeBtn) closeBtn.addEventListener("click", closeMenu);
    if (overlay) overlay.addEventListener("click", closeMenu);
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeMenu();
    });
  }

  function renderAuthUi() {
    if (!authSectionEl) return;
    var logged = !!authState.logged_in;
    if (authUserEl) {
      authUserEl.textContent = logged
        ? ("Zalogowany: " + String(authState.email || "") + " | rola: " + roleLabel(authState.role))
        : "Nie jesteś zalogowany.";
    }

    if (authEmailEl) authEmailEl.disabled = logged;
    if (authPasswordEl) authPasswordEl.disabled = logged;

    var submitBtn = authFormEl ? authFormEl.querySelector('button[type="submit"]') : null;
    if (submitBtn) submitBtn.hidden = logged;
    if (authLogoutBtn) authLogoutBtn.hidden = !logged;

    updateAuthMenuButtons();
    updateVideoAddMenuButtons();
  }

  async function fetchAuthStatus() {
    var response = await fetch(AUTH_API_URL + "?action=status", { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się pobrać statusu logowania.");
    authState = data.user || { logged_in: false, user_id: null, email: null, role: null };
    csrfToken = String(data.csrf_token || "");
    if (authCsrfEl) authCsrfEl.value = csrfToken;
    renderAuthUi();
    return data;
  }

  async function loginInline(event) {
    event.preventDefault();
    setAuthStatus("Logowanie...");
    var email = String(authEmailEl && authEmailEl.value || "").trim();
    var password = String(authPasswordEl && authPasswordEl.value || "");
    if (!email || !password) {
      setAuthStatus("Podaj e-mail i hasło.");
      return;
    }
    try {
      var response = await fetch(AUTH_API_URL + "?action=login", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          email: email,
          password: password,
          csrf_token: csrfToken
        })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się zalogować.");
      authState = data.user || authState;
      csrfToken = String(data.csrf_token || csrfToken);
      if (authCsrfEl) authCsrfEl.value = csrfToken;
      if (authPasswordEl) authPasswordEl.value = "";
      renderAuthUi();
      setAuthStatus("Zalogowano.");
      closeAuthModal();
      await refreshAccessAndContent();
    } catch (error) {
      setAuthStatus(error instanceof Error ? error.message : "Błąd logowania.");
    }
  }

  async function logoutInline() {
    setAuthStatus("Wylogowywanie...");
    try {
      var response = await fetch(AUTH_API_URL + "?action=logout", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ csrf_token: csrfToken })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się wylogować.");
      authState = data.user || { logged_in: false, user_id: null, email: null, role: null };
      csrfToken = String(data.csrf_token || "");
      if (authCsrfEl) authCsrfEl.value = csrfToken;
      renderAuthUi();
      setAuthStatus("Wylogowano.");
      closeAuthModal();
      await refreshAccessAndContent();
    } catch (error) {
      setAuthStatus(error instanceof Error ? error.message : "Błąd wylogowania.");
    }
  }

  async function upsertVideoFromUrl(youtubeUrl) {
    var response = await fetch(API_URL + "?action=upsert_video", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ youtube_url: String(youtubeUrl || "").trim() })
    });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok || !data.video || !data.video.youtube_id) {
      throw new Error(data.message || "Nie udało się dodać filmu.");
    }
    return data;
  }

  async function submitVideoAdd(event) {
    event.preventDefault();
    if (!canCurrentUserAddVideo()) {
      setVideoAddStatus("Brak uprawnień do dodawania filmów.");
      closeAddModal();
      return;
    }
    var value = String(videoAddInputEl && videoAddInputEl.value || "").trim();
    if (!value) {
      setVideoAddStatus("Wklej link YouTube lub ID.");
      return;
    }
    setVideoAddStatus("Dodawanie filmu...");
    try {
      var data = await upsertVideoFromUrl(value);
      var newId = String(data.video.youtube_id || "").trim();
      if (!newId) throw new Error("Brak ID filmu w odpowiedzi.");
      setVideoAddStatus(data.created ? "Film dodany." : "Film już istnieje.");
      if (videoAddInputEl) videoAddInputEl.value = "";
      await reloadVideoContext();
      closeAddModal();
      window.location.href = buildVideoUrl(newId);
    } catch (error) {
      setVideoAddStatus(error instanceof Error ? error.message : "Nie udało się dodać filmu.");
    }
  }

  async function fetchVideoList() {
    var url = API_URL + "?action=list_videos" + (editMode ? "&edit=1" : "");
    var response = await fetch(url, { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok || !Array.isArray(data.videos)) {
      throw new Error(data.message || "Nie udało się pobrać listy filmów.");
    }
    return data;
  }

  function updateAccessInfo(access) {
    accessInfo = access || null;
    syncAuthFromAccess(accessInfo);
    renderAuthUi();
    applyDefaultAuthor(accessInfo);

    var identityParts = [];
    if (authState && authState.logged_in) {
      var email = String(authState.email || "").trim();
      var role = String(authState.role || "").trim();
      var roleText = role ? (" (" + roleLabel(role) + ")") : "";
      identityParts.push("User: " + (email || "zalogowany") + roleText);
    }
    if (accessInfo && accessInfo.token && accessInfo.token.token_id) {
      var tokenId = String(accessInfo.token.token_id || "").trim();
      var tokenSource = accessInfo.token.resource_type === "video" && accessInfo.token.resource_id
        ? (" | film: " + accessInfo.token.resource_id)
        : "";
      identityParts.push("Token ID: " + tokenId + tokenSource);
    }
    setTokenInfo(identityParts.join(" | "));
  }

  function renderVideoList() {
    var selectEls = getVideoListSelectElements();
    if (!selectEls.length) return;
    selectEls.forEach(function (selectEl) {
      selectEl.innerHTML = "";
      var placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = videos.length ? "Wybierz film..." : "Brak filmów w bazie";
      selectEl.appendChild(placeholder);

      videos.forEach(function (video) {
        var option = document.createElement("option");
        option.value = String(video.youtube_id || "");
        option.textContent = resolveVideoTitle(video);
        if (option.value === source) option.selected = true;
        selectEl.appendChild(option);
      });
    });

    if (videos.length && source) {
      var exists = videos.some(function (video) { return String(video.youtube_id || "") === source; });
      if (!exists) setVideoListValue("");
    } else if (source) {
      setVideoListValue(source);
    }
  }

  function handleVideoSelectChange(event) {
    var nextSource = String(event && event.target && event.target.value || "").trim();
    if (!nextSource || nextSource === source) return;
    if (accessInfo && accessInfo.token && accessInfo.token.resource_type === "video" &&
      accessInfo.token.resource_id && nextSource !== accessInfo.token.resource_id) {
      setVideoListStatus("Ten token pozwala tylko na jeden film: " + accessInfo.token.resource_id);
      setVideoListValue(source);
      return;
    }
    setVideoListStatus("Przełączanie filmu...");
    closeDesktopVideoMenuPanel(false);
    if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
    window.location.href = buildVideoUrl(nextSource);
  }

  function escapeHtml(text) {
    return String(text || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function formatTime(seconds) {
    var sec = Math.max(0, Number(seconds) || 0);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    if (h > 0) return h + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
    return String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
  }

  function parseTimeText(value) {
    var raw = String(value || "").trim();
    if (!raw) return null;
    var parts = raw.split(":").map(function (part) { return part.trim(); });
    if (parts.length === 2) {
      var m = Number(parts[0]); var s = Number(parts[1]);
      if (!Number.isInteger(m) || !Number.isInteger(s) || m < 0 || s < 0 || s > 59) return null;
      return (m * 60) + s;
    }
    if (parts.length === 3) {
      var h = Number(parts[0]); var mm = Number(parts[1]); var ss = Number(parts[2]);
      if (!Number.isInteger(h) || !Number.isInteger(mm) || !Number.isInteger(ss) || h < 0 || mm < 0 || mm > 59 || ss < 0 || ss > 59) return null;
      return (h * 3600) + (mm * 60) + ss;
    }
    return null;
  }

  function sortCommentsByTime() {
    comments.sort(function (a, b) {
      var at = Number(a && a.czas_sekundy || 0);
      var bt = Number(b && b.czas_sekundy || 0);
      if (at !== bt) return at - bt;
      return Number(a && a.id || 0) - Number(b && b.id || 0);
    });
  }

  function renderComments() {
    if (!commentsListEl) return;
    commentsListEl.innerHTML = "";
    if (!comments.length) {
      if (commentsEmptyEl) commentsEmptyEl.hidden = false;
      return;
    }
    if (commentsEmptyEl) commentsEmptyEl.hidden = true;

    var fragment = document.createDocumentFragment();
    comments.forEach(function (comment) {
      var item = document.createElement("li");
      item.className = "video-comments__item";
      item.dataset.id = String(comment.id);

      var jumpBtn = document.createElement("button");
      jumpBtn.type = "button";
      jumpBtn.className = "video-comments__jump";
      jumpBtn.setAttribute("aria-label", "Przejdź do " + (comment.czas_tekst || formatTime(comment.czas_sekundy || 0)));
      var timeLabel = escapeHtml(comment.czas_tekst || formatTime(comment.czas_sekundy || 0));
      var authorLabel = String(comment.autor || "").trim();
      if (authorLabel) {
        timeLabel += " · " + escapeHtml(authorLabel);
      }
      jumpBtn.innerHTML =
        '<span class="video-comments__time">' + timeLabel + "</span>" +
        '<span class="video-comments__title">' + escapeHtml(comment.tytul || "Komentarz") + "</span>" +
        '<span class="video-comments__content">' + escapeHtml(comment.tresc || "") + "</span>";
      jumpBtn.addEventListener("click", function () { seekAndPlay(comment.czas_sekundy || 0, comment.id); });
      item.appendChild(jumpBtn);

      if (editMode) {
        var actions = document.createElement("div");
        actions.className = "video-comments__actions";

        var editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "video-comments__action";
        editBtn.textContent = "Edytuj";
        editBtn.addEventListener("click", function (event) { startEditComment(comment, event.currentTarget); });

        var deleteBtn = document.createElement("button");
        deleteBtn.type = "button";
        deleteBtn.className = "video-comments__action video-comments__action--danger";
        deleteBtn.textContent = "Usuń";
        deleteBtn.addEventListener("click", function () { deleteComment(comment.id); });

        actions.appendChild(editBtn);
        actions.appendChild(deleteBtn);
        item.appendChild(actions);
      }
      fragment.appendChild(item);
    });
    commentsListEl.appendChild(fragment);
  }

  function seekAndPlay(seconds) {
    if (!playerReady || !player) return;
    player.seekTo(Math.max(0, Number(seconds) || 0), true);
    player.playVideo();
  }

  function clearPauseAutoOpenCandidate() {
    if (pauseAutoOpenTimer) {
      window.clearTimeout(pauseAutoOpenTimer);
      pauseAutoOpenTimer = null;
    }
    pauseAutoOpenCandidate = null;
  }

  function readCurrentPlayerTime() {
    if (!player || typeof player.getCurrentTime !== "function") return 0;
    var value = Number(player.getCurrentTime() || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
  }

  function scheduleAutoCommentOpenOnPause() {
    clearPauseAutoOpenCandidate();
    if (!editMode || isCommentModalOpen()) return;
    var pausedAt = readCurrentPlayerTime();
    pauseAutoOpenCandidate = { pausedAt: pausedAt, createdAt: Date.now() };
    pauseAutoOpenTimer = window.setTimeout(function () {
      pauseAutoOpenTimer = null;
      if (!pauseAutoOpenCandidate) return;
      if (lastPlayerState !== 2) {
        pauseAutoOpenCandidate = null;
        return;
      }
      var nowAt = readCurrentPlayerTime();
      if (Math.abs(nowAt - pauseAutoOpenCandidate.pausedAt) > 0.35) {
        pauseAutoOpenCandidate = null;
        return;
      }
      pauseAutoOpenCandidate = null;
      showFormAtCurrentTime(null);
    }, 280);
  }

  function showFormAtCurrentTime(triggerEl) {
    if (!editMode || !playerReady || !player || !timeInput || !timeTextInput || !formEl || !titleInput) return;
    if (isCommentModalOpen()) return;
    clearPauseAutoOpenCandidate();
    var seconds = Math.max(0, Math.floor(player.getCurrentTime() || 0));
    timeInput.value = String(seconds);
    timeTextInput.value = formatTime(seconds);
    if (formStatusEl) formStatusEl.textContent = "";
    editingCommentId = null;
    if (commentIdInput) commentIdInput.value = "";
    var submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = "Zapisz komentarz";
    applyDefaultAuthor(accessInfo);
    setCommentFormMode(false);
    if (!openCommentModal(triggerEl || null)) return;
    if (contentInput) contentInput.focus();
  }

  function hideForm(restoreFocus) {
    stopTranscription();
    clearPauseAutoOpenCandidate();
    if (!formEl || !variantInput) return;
    formEl.reset();
    variantInput.value = "ogolny";
    if (formStatusEl) formStatusEl.textContent = "";
    setTranscribeStatus("");
    editingCommentId = null;
    if (commentIdInput) commentIdInput.value = "";
    var submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = "Zapisz komentarz";
    setCommentFormMode(false);
    closeCommentModal(restoreFocus !== false);
  }

  function startEditComment(comment, triggerEl) {
    if (!editMode || !titleInput || !timeInput || !timeTextInput) return;
    editingCommentId = Number(comment.id);
    if (commentIdInput) commentIdInput.value = String(comment.id);
    timeInput.value = String(comment.czas_sekundy || 0);
    timeTextInput.value = String(comment.czas_tekst || formatTime(comment.czas_sekundy || 0));
    if (titleInput) titleInput.value = String(comment.tytul || "");
    if (contentInput) contentInput.value = String(comment.tresc || "");
    if (variantInput) variantInput.value = String(comment.wariant || "ogolny");
    if (authorInput) authorInput.value = String(comment.autor || "");
    if (formStatusEl) formStatusEl.textContent = "";
    var submitBtn = formEl ? formEl.querySelector('button[type="submit"]') : null;
    if (submitBtn) submitBtn.textContent = "Zapisz zmiany";
    setCommentFormMode(false);
    openCommentModal(triggerEl || null);
    if (contentInput) contentInput.focus();
  }

  async function deleteComment(commentId) {
    if (!editMode) return;
    if (!window.confirm("Usuń ten komentarz?")) return;
    try {
      var response = await fetch(API_URL + "?action=delete_comment", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ source: source, comment_id: Number(commentId) })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się usunąć komentarza.");
      comments = comments.filter(function (c) { return Number(c.id) !== Number(commentId); });
      renderComments();
      if (Number(editingCommentId) === Number(commentId)) hideForm();
      setStatus("Komentarz usunięty.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd usuwania komentarza.");
    }
  }

  function loadYouTube(videoId) {
    if (!videoId) return;
    if (window.YT && window.YT.Player) {
      if (player && typeof player.loadVideoById === "function") {
        player.loadVideoById(videoId);
        playerReady = true;
        setStatus("Gotowe");
      } else {
        buildPlayer(videoId);
      }
      return;
    }

    window.onYouTubeIframeAPIReady = function () {
      buildPlayer(videoId);
    };
    if (ytApiRequested) return;
    ytApiRequested = true;
    var tag = document.createElement("script");
    tag.src = "https://www.youtube.com/iframe_api";
    tag.async = true;
    document.head.appendChild(tag);
  }

  function buildPlayer(videoId) {
    player = new window.YT.Player("yt-player", {
      videoId: videoId,
      playerVars: { rel: 0, modestbranding: 1, playsinline: 1 },
      events: {
        onReady: function () {
          playerReady = true;
          lastPlayerState = -1;
          clearPauseAutoOpenCandidate();
          setStatus("Gotowe");
        },
        onStateChange: function (event) {
          var nextState = Number(event && event.data);
          var prevState = lastPlayerState;
          lastPlayerState = nextState;

          if (!editMode) {
            if (addCommentBtn) addCommentBtn.hidden = true;
            clearPauseAutoOpenCandidate();
            return;
          }
          if (nextState === 1) {
            if (addCommentBtn && !isCommentModalOpen()) addCommentBtn.hidden = true;
            clearPauseAutoOpenCandidate();
            return;
          }
          if (nextState === 3 || nextState === 5) {
            clearPauseAutoOpenCandidate();
            return;
          }
          if (nextState === 2) {
            if (addCommentBtn) addCommentBtn.hidden = false;
            if (prevState === 1 && !isCommentModalOpen()) {
              scheduleAutoCommentOpenOnPause();
            }
            return;
          }
          if (nextState === 0 || nextState === -1) clearPauseAutoOpenCandidate();
        }
      }
    });
  }

  async function fetchData() {
    var url = API_URL + "?action=load&source=" + encodeURIComponent(source) + (editMode ? "&edit=1" : "");
    var response = await fetch(url, { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się pobrać danych filmu.");
    return data;
  }

  async function submitComment(event) {
    event.preventDefault();
    stopTranscription();
    if (!formStatusEl || !timeInput || !timeTextInput || !titleInput || !contentInput || !variantInput || !authorInput) return;
    formStatusEl.textContent = "Zapisywanie...";

    var seconds = Math.max(0, Math.floor(Number(timeInput.value || "0") || 0));
    var parsedFromText = parseTimeText(timeTextInput.value || "");
    if (parsedFromText !== null) seconds = parsedFromText;
    if (seconds > 86400) {
      formStatusEl.textContent = "Czas komentarza jest poza zakresem.";
      return;
    }
    timeInput.value = String(seconds);
    timeTextInput.value = formatTime(seconds);

    var actionName = editingCommentId ? "update_comment" : "add_comment";
    var payload = {
      action: actionName,
      source: source,
      comment_id: editingCommentId ? Number(editingCommentId) : undefined,
      czas_sekundy: String(seconds),
      czas_tekst: String(timeTextInput.value || "").trim(),
      tytul: String(titleInput.value || "").trim(),
      tresc: String(contentInput.value || "").trim(),
      wariant: String(variantInput.value || "").trim(),
      autor: String(authorInput.value || "").trim()
    };
    setStoredAuthor(payload.autor);

    try {
      var response = await fetch(API_URL + "?action=" + encodeURIComponent(actionName), {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(payload)
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok || !data.comment) throw new Error(data.message || "Nie udało się zapisać komentarza.");

      if (editingCommentId) {
        comments = comments.map(function (c) {
          return Number(c.id) === Number(data.comment.id) ? Object.assign({}, c, data.comment) : c;
        });
      } else {
        comments.push(data.comment);
      }
      sortCommentsByTime();
      renderComments();
      formStatusEl.textContent = editingCommentId ? "Komentarz zaktualizowany." : "Komentarz zapisany.";
      hideForm();
      if (addCommentBtn) addCommentBtn.hidden = false;
    } catch (error) {
      formStatusEl.textContent = error instanceof Error ? error.message : "Błąd zapisu.";
    }
  }

  function setTranscribeButtonState(active) {
    if (!transcribeBtn) return;
    transcribeBtn.classList.toggle("is-recording", !!active);
    transcribeBtn.setAttribute("aria-pressed", active ? "true" : "false");
    transcribeBtn.title = active ? "Zatrzymaj dyktowanie" : "Dyktowanie głosowe";
  }

  function stopMediaStream() {
    if (!mediaStream) return;
    try { mediaStream.getTracks().forEach(function (track) { track.stop(); }); } catch (error) {}
    mediaStream = null;
  }

  function appendTranscript(text) {
    if (!contentInput) return;
    var transcript = String(text || "").trim();
    if (!transcript) return;
    var current = String(contentInput.value || "");
    var glue = current && !/\s$/.test(current) ? " " : "";
    contentInput.value = current + glue + transcript;
  }

  function stopTranscription() {
    if (!speechRecognition || !isTranscribing) return;
    speechRecognition.stop();
    stopMediaStream();
  }

  function clearTranscribeSilenceTimer() {
    if (!transcribeSilenceTimer) return;
    window.clearTimeout(transcribeSilenceTimer);
    transcribeSilenceTimer = null;
  }

  function resolveMicrophoneErrorMessage(error) {
    var errName = String(error && error.name || "");
    var errMsg = String(error && error.message || "").toLowerCase();
    if (errMsg.indexOf("permissions policy") !== -1 || errMsg.indexOf("microphone is not allowed in this document") !== -1) {
      return "Mikrofon jest zablokowany przez konfigurację serwera (Permissions-Policy).";
    }
    if (errName === "NotAllowedError" || errName === "PermissionDeniedError") return "Brak zgody na mikrofon.";
    if (errName === "NotFoundError") return "Nie wykryto mikrofonu.";
    return "Nie udało się uruchomić dyktowania.";
  }

  async function startTranscription() {
    if (!speechRecognition) return;
    if (!window.isSecureContext) {
      setTranscribeStatus("Dyktowanie wymaga HTTPS (lub localhost).");
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setTranscribeStatus("Przeglądarka nie wspiera dostępu do mikrofonu.");
      return;
    }
    setTranscribeStatus("Prośba o dostęp do mikrofonu...");
    try {
      mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      speechRecognition.start();
    } catch (error) {
      stopMediaStream();
      setTranscribeStatus(resolveMicrophoneErrorMessage(error));
    }
  }

  function initSpeechRecognition() {
    if (!transcribeBtn) return;
    if (!SpeechRecognitionCtor) {
      transcribeBtn.disabled = true;
      setTranscribeStatus("Dyktowanie nie jest wspierane w tej przeglądarce.");
      return;
    }
    speechRecognition = new SpeechRecognitionCtor();
    speechRecognition.lang = "pl-PL";
    speechRecognition.continuous = false;
    speechRecognition.interimResults = true;
    speechRecognition.maxAlternatives = 1;

    speechRecognition.onstart = function () {
      isTranscribing = true;
      transcribeHeardSpeech = false;
      clearTranscribeSilenceTimer();
      transcribeSilenceTimer = window.setTimeout(function () {
        if (isTranscribing && !transcribeHeardSpeech) {
          setTranscribeStatus("Nie wykryto mowy. Mów wyraźnie i bliżej mikrofonu.");
        }
      }, 4000);
      setTranscribeButtonState(true);
      setTranscribeStatus("Nagrywanie... mów teraz.");
    };
    speechRecognition.onend = function () {
      isTranscribing = false;
      clearTranscribeSilenceTimer();
      setTranscribeButtonState(false);
      stopMediaStream();
      if (!transcribeHeardSpeech) {
        setTranscribeStatus("Brak rozpoznanej mowy. Spróbuj ponownie.");
      } else if (!transcribeStatusEl || !transcribeStatusEl.textContent || transcribeStatusEl.textContent.indexOf("Błąd") !== 0) {
        setTranscribeStatus("Dyktowanie zatrzymane.");
      }
    };
    speechRecognition.onerror = function (event) {
      clearTranscribeSilenceTimer();
      var code = String(event && event.error || "");
      var message = "Błąd dyktowania.";
      if (code === "not-allowed" || code === "service-not-allowed") message = "Brak zgody na mikrofon.";
      else if (code === "no-speech") message = "Nie wykryto mowy.";
      else if (code === "audio-capture") message = "Nie wykryto mikrofonu.";
      else if (code === "network") message = "Błąd usługi rozpoznawania mowy (network).";
      if (String(event && event.message || "").toLowerCase().indexOf("permissions policy") !== -1) {
        message = "Mikrofon jest zablokowany przez konfigurację serwera (Permissions-Policy).";
      }
      setTranscribeStatus(message);
    };
    speechRecognition.onresult = function (event) {
      transcribeHeardSpeech = true;
      clearTranscribeSilenceTimer();
      var interim = "";
      for (var i = event.resultIndex; i < event.results.length; i += 1) {
        var transcript = String(event.results[i][0] && event.results[i][0].transcript || "").trim();
        if (!transcript) continue;
        if (event.results[i].isFinal) appendTranscript(transcript);
        else interim += transcript + " ";
      }
      if (interim.trim()) setTranscribeStatus("Słyszę: " + interim.trim());
      else if (isTranscribing) setTranscribeStatus("Nagrywanie... mów teraz.");
    };

    transcribeBtn.addEventListener("click", function () {
      if (isTranscribing) stopTranscription();
      else startTranscription();
    });
  }

  function applyListData(listData) {
    videos = listData && Array.isArray(listData.videos) ? listData.videos : [];
    updateAccessInfo(listData ? (listData.access || null) : null);
    var allowed = computeContentAccess(accessInfo);
    applyAccessState(allowed);
    if (!allowed) {
      setStatus("Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
      setVideoListStatus("");
      return false;
    }
    renderVideoList();
    var countMsg = videos.length ? ("Filmów w bazie: " + videos.length + ".") : "Brak filmów do wyboru.";
    setVideoListStatus(countMsg);
    return true;
  }

  async function reloadVideoContext(preloadedListData) {
    clearPauseAutoOpenCandidate();
    if (!hasContentAccess && !preloadedListData) {
      applyAccessState(false);
      return;
    }

    setVideoListStatus("Ładowanie listy filmów...");
    try {
      var listData = preloadedListData || await fetchVideoList();
      if (!applyListData(listData)) return;

      if (accessInfo && accessInfo.token && accessInfo.token.resource_type === "video" &&
          accessInfo.token.resource_id && source && source !== accessInfo.token.resource_id) {
        setStatus("Ten token pozwala tylko na film: " + accessInfo.token.resource_id + ". Przełączam...");
        window.location.href = buildVideoUrl(accessInfo.token.resource_id);
        return;
      }
    } catch (error) {
      applyAccessState(false);
      setVideoListStatus(error instanceof Error ? error.message : "Błąd ładowania listy filmów.");
      setStatus("Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
      return;
    }

    if (!source) {
      setStatus("Brak parametru source. Użyj np. video.html?source=YOUTUBE_ID");
      comments = [];
      renderComments();
      return;
    }
    if (!isYoutubeId(source)) {
      setStatus("Niepoprawny parametr source.");
      comments = [];
      renderComments();
      return;
    }

    try {
      var data = await fetchData();
      updateAccessInfo(data.access || accessInfo);
      if (!computeContentAccess(accessInfo)) {
        applyAccessState(false);
        setStatus("Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
        return;
      }
      applyAccessState(true);
      if (titleEl) titleEl.textContent = resolveVideoTitle(data.video);
      var canEditCurrentSource = !!(
        data &&
        data.access &&
        data.access.effective &&
        data.access.effective.can_edit_source === true
      );
      editMode = !!data.edit || canEditCurrentSource;
      comments = Array.isArray(data.comments) ? data.comments : [];
      sortCommentsByTime();
      renderComments();
      loadYouTube(data.video.youtube_id || source);
      setStatus(editMode ? "Ładowanie odtwarzacza..." : "Tryb podglądu.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd ładowania.");
      comments = [];
      renderComments();
      if (addCommentBtn) addCommentBtn.hidden = true;
      hideForm(false);
    }
  }

  async function refreshAccessAndContent() {
    try {
      var listData = await fetchVideoList();
      if (!applyListData(listData)) return;
      await reloadVideoContext(listData);
    } catch (error) {
      applyAccessState(false);
      setVideoListStatus(error instanceof Error ? error.message : "Błąd ładowania listy filmów.");
      setStatus("Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
    }
  }

  async function init() {
    initSpeechRecognition();
    initMobileMenuHandlers();
    scheduleVideoMenuButtons();
    document.addEventListener("mousedown", handleDesktopVideoMenuGlobalPointer);
    document.addEventListener("touchstart", handleDesktopVideoMenuGlobalPointer, { passive: true });
    document.addEventListener("keydown", handleDesktopVideoMenuGlobalKeydown);

    var storedAuthor = getStoredAuthor();
    if (authorInput && !String(authorInput.value || "").trim() && storedAuthor) {
      authorInput.value = storedAuthor;
    }
    if (authorInput) {
      authorInput.addEventListener("change", function () { setStoredAuthor(authorInput.value || ""); });
      authorInput.addEventListener("blur", function () { setStoredAuthor(authorInput.value || ""); });
    }

    if (authFormEl) authFormEl.addEventListener("submit", loginInline);
    if (authLogoutBtn) authLogoutBtn.addEventListener("click", logoutInline);
    if (authCloseBtn) authCloseBtn.addEventListener("click", closeAuthModal);
    if (authModalOverlayEl) authModalOverlayEl.addEventListener("click", closeAuthModal);
    if (addCloseBtn) addCloseBtn.addEventListener("click", closeAddModal);
    if (addModalOverlayEl) addModalOverlayEl.addEventListener("click", closeAddModal);
    if (commentModalCloseBtn) commentModalCloseBtn.addEventListener("click", function () { hideForm(true); });
    if (commentModalOverlayEl) commentModalOverlayEl.addEventListener("click", function () { hideForm(true); });
    document.addEventListener("keydown", handleAuthModalKeydown);
    document.addEventListener("keydown", handleAddModalKeydown);
    document.addEventListener("keydown", handleCommentModalKeydown);
    if (videoAddFormEl) videoAddFormEl.addEventListener("submit", submitVideoAdd);

    if (accessToken) {
      setStatus("Weryfikacja tokenu dostępu...");
      try {
        await exchangeAccessToken(accessToken);
        removeAccessTokenFromUrl();
        accessToken = "";
      } catch (error) {
        setStatus(error instanceof Error ? error.message : "Nie udało się zweryfikować tokenu.");
      }
    }

    try {
      await fetchAuthStatus();
    } catch (error) {
      setAuthStatus(error instanceof Error ? error.message : "Nie udało się pobrać statusu logowania.");
    }

    await refreshAccessAndContent();
  }

  if (addCommentBtn) addCommentBtn.addEventListener("click", function (event) { showFormAtCurrentTime(event.currentTarget); });
  if (cancelBtn) cancelBtn.addEventListener("click", function () { hideForm(true); });
  if (formEl) formEl.addEventListener("submit", submitComment);
  if (addCommentBtn) addCommentBtn.hidden = true;

  init();
})();

