(function () {
  "use strict";

  var API_URL = "/backend/video.php";
  var AUTH_API_URL = "/backend/video_auth.php";
  var ACCESS_API_URL = "/backend/access_token.php";
  var AUTHOR_STORAGE_KEY = "video_comment_author";
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
  var videoListSectionEl = document.getElementById("video-list-section");
  var videoListSelectEl = document.getElementById("video-list-select");
  var videoListStatusEl = document.getElementById("video-list-status");
  var commentsListEl = document.getElementById("comments-list");
  var commentsEmptyEl = document.getElementById("comments-empty");
  var addCommentBtn = document.getElementById("add-comment-btn");
  var formSection = document.getElementById("comment-form-section");
  var formEl = document.getElementById("comment-form");
  var formStatusEl = document.getElementById("form-status");
  var cancelBtn = document.getElementById("cancel-comment-btn");
  var timeInput = document.getElementById("comment-time");
  var timeTextInput = document.getElementById("comment-time-text");
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
  var editingCommentId = null;
  var accessInfo = null;
  var hasContentAccess = false;
  var authState = { logged_in: false, user_id: null, email: null, role: null };
  var csrfToken = "";
  var ytApiRequested = false;
  var authMenuTrigger = null;
  var addMenuTrigger = null;
  var previouslyFocusedEl = null;
  var closeMobileMenuFn = null;

  var SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var speechRecognition = null;
  var isTranscribing = false;
  var mediaStream = null;

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
  }
  function setVideoListStatus(msg) {
    if (videoListStatusEl) videoListStatusEl.textContent = msg || "";
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

  function getStoredAuthor() {
    try { return String(window.localStorage.getItem(AUTHOR_STORAGE_KEY) || "").trim(); }
    catch (error) { return ""; }
  }
  function setStoredAuthor(value) {
    try {
      var normalized = String(value || "").trim();
      if (normalized) window.localStorage.setItem(AUTHOR_STORAGE_KEY, normalized);
      else window.localStorage.removeItem(AUTHOR_STORAGE_KEY);
    } catch (error) { }
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
    if (videoListSectionEl) videoListSectionEl.hidden = !hasContentAccess;
    if (playerWrapEl) playerWrapEl.hidden = !hasContentAccess;
    if (commentsSectionEl) commentsSectionEl.hidden = !hasContentAccess;
    if (accessMessageEl) accessMessageEl.hidden = hasContentAccess;
    if (!hasContentAccess) {
      comments = [];
      renderComments();
      if (videoListSelectEl) videoListSelectEl.innerHTML = "<option value=\"\">Brak dostępu</option>";
      if (addCommentBtn) addCommentBtn.hidden = true;
      if (formSection) formSection.hidden = true;
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
    if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
    openAuthModal();
  }

  function openAddModalFromMenu(event) {
    if (!canCurrentUserAddVideo()) return;
    addMenuTrigger = event && event.currentTarget ? event.currentTarget : null;
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

  function ensureVideoMenuButtons() {
    var navDesktop = document.getElementById("navDesktop");
    var navMobile = document.getElementById("navMobile");
    if (!navDesktop || !navMobile) return false;

    var desktopBtn = document.getElementById("video-auth-trigger-desktop");
    if (!desktopBtn) {
      desktopBtn = document.createElement("button");
      desktopBtn.id = "video-auth-trigger-desktop";
      desktopBtn.type = "button";
      desktopBtn.className = "btn-menu";
      desktopBtn.textContent = "Logowanie";
      desktopBtn.addEventListener("click", openAuthModalFromMenu);
      navDesktop.appendChild(desktopBtn);
    }

    var mobileBtn = document.getElementById("video-auth-trigger-mobile");
    if (!mobileBtn) {
      mobileBtn = document.createElement("button");
      mobileBtn.id = "video-auth-trigger-mobile";
      mobileBtn.type = "button";
      mobileBtn.className = "dd-row block px-3 py-2 rounded-lg text-brand-primary";
      mobileBtn.textContent = "Logowanie";
      mobileBtn.addEventListener("click", openAuthModalFromMenu);
      navMobile.appendChild(mobileBtn);
    }

    var desktopAddBtn = document.getElementById("video-add-trigger-desktop");
    if (!desktopAddBtn) {
      desktopAddBtn = document.createElement("button");
      desktopAddBtn.id = "video-add-trigger-desktop";
      desktopAddBtn.type = "button";
      desktopAddBtn.className = "btn-menu";
      desktopAddBtn.textContent = "Dodaj video";
      desktopAddBtn.hidden = true;
      desktopAddBtn.addEventListener("click", openAddModalFromMenu);
      navDesktop.appendChild(desktopAddBtn);
    }

    var mobileAddBtn = document.getElementById("video-add-trigger-mobile");
    if (!mobileAddBtn) {
      mobileAddBtn = document.createElement("button");
      mobileAddBtn.id = "video-add-trigger-mobile";
      mobileAddBtn.type = "button";
      mobileAddBtn.className = "dd-row block px-3 py-2 rounded-lg text-brand-primary";
      mobileAddBtn.textContent = "Dodaj video";
      mobileAddBtn.hidden = true;
      mobileAddBtn.addEventListener("click", openAddModalFromMenu);
      navMobile.appendChild(mobileAddBtn);
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

  function initMobileMenuHandlers() {
    var openBtn = document.getElementById("menuToggleHome");
    var closeBtn = document.getElementById("menuCloseHome");
    var overlay = document.getElementById("mobileMenuOverlayHome");
    var menu = document.getElementById("mobileMenuHome");

    function openMenu() {
      if (!overlay || !menu) return;
      overlay.classList.remove("hidden");
      menu.classList.remove("translate-x-full");
    }

    function closeMenu() {
      if (!overlay || !menu) return;
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

    if (!accessInfo || !accessInfo.token || !accessInfo.token.token_id) {
      setTokenInfo("");
      return;
    }
    var tokenId = accessInfo.token.token_id;
    var tokenSource = accessInfo.token.resource_type === "video" && accessInfo.token.resource_id
      ? (" | Ograniczenie filmu: " + accessInfo.token.resource_id)
      : "";
    setTokenInfo("Token ID: " + tokenId + tokenSource);
  }

  function renderVideoList() {
    if (!videoListSelectEl) return;
    videoListSelectEl.innerHTML = "";
    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = videos.length ? "Wybierz film..." : "Brak filmów w bazie";
    videoListSelectEl.appendChild(placeholder);

    videos.forEach(function (video) {
      var option = document.createElement("option");
      option.value = String(video.youtube_id || "");
      option.textContent = String(video.tytul || ("YouTube video " + option.value));
      if (option.value === source) option.selected = true;
      videoListSelectEl.appendChild(option);
    });

    if (videos.length && source) {
      var exists = videos.some(function (video) { return String(video.youtube_id || "") === source; });
      if (!exists) videoListSelectEl.value = "";
    }
  }

  function handleVideoSelectChange(event) {
    var nextSource = String(event && event.target && event.target.value || "").trim();
    if (!nextSource || nextSource === source) return;
    if (accessInfo && accessInfo.token && accessInfo.token.resource_type === "video" &&
      accessInfo.token.resource_id && nextSource !== accessInfo.token.resource_id) {
      setVideoListStatus("Ten token pozwala tylko na jeden film: " + accessInfo.token.resource_id);
      if (videoListSelectEl) videoListSelectEl.value = source;
      return;
    }
    setVideoListStatus("Przełączanie filmu...");
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
      jumpBtn.innerHTML =
        '<span class="video-comments__time">' + escapeHtml(comment.czas_tekst || formatTime(comment.czas_sekundy || 0)) + "</span>" +
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
        editBtn.addEventListener("click", function () { startEditComment(comment); });

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

  function showFormAtCurrentTime() {
    if (!playerReady || !player || !timeInput || !timeTextInput || !formSection || !formEl || !titleInput) return;
    var seconds = Math.max(0, Math.floor(player.getCurrentTime() || 0));
    timeInput.value = String(seconds);
    timeTextInput.value = formatTime(seconds);
    if (formStatusEl) formStatusEl.textContent = "";
    formSection.hidden = false;
    editingCommentId = null;
    if (commentIdInput) commentIdInput.value = "";
    var submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = "Zapisz komentarz";
    titleInput.focus();
  }

  function hideForm() {
    stopTranscription();
    if (!formSection || !formEl || !variantInput) return;
    formSection.hidden = true;
    formEl.reset();
    variantInput.value = "ogolny";
    if (formStatusEl) formStatusEl.textContent = "";
    setTranscribeStatus("");
    editingCommentId = null;
    if (commentIdInput) commentIdInput.value = "";
    var submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = "Zapisz komentarz";
  }

  function startEditComment(comment) {
    if (!editMode || !formSection || !titleInput || !timeInput || !timeTextInput) return;
    formSection.hidden = false;
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
    titleInput.focus();
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
        onReady: function () { playerReady = true; setStatus("Gotowe"); },
        onStateChange: function (event) {
          if (!addCommentBtn) return;
          if (editMode && event.data === 2) addCommentBtn.hidden = false;
          else if (event.data === 1) addCommentBtn.hidden = true;
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
      comments.sort(function (a, b) {
        var ao = Number(a.kolejnosc || 0);
        var bo = Number(b.kolejnosc || 0);
        if (ao !== bo) return ao - bo;
        return Number(a.czas_sekundy || 0) - Number(b.czas_sekundy || 0);
      });
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
      var message = "Nie udało się uruchomić dyktowania.";
      var errName = String(error && error.name || "");
      if (errName === "NotAllowedError" || errName === "PermissionDeniedError") message = "Brak zgody na mikrofon.";
      else if (errName === "NotFoundError") message = "Nie wykryto mikrofonu.";
      setTranscribeStatus(message);
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
    speechRecognition.continuous = true;
    speechRecognition.interimResults = true;

    speechRecognition.onstart = function () {
      isTranscribing = true;
      setTranscribeButtonState(true);
      setTranscribeStatus("Nagrywanie... mów teraz.");
    };
    speechRecognition.onend = function () {
      isTranscribing = false;
      setTranscribeButtonState(false);
      stopMediaStream();
      if (!transcribeStatusEl || !transcribeStatusEl.textContent || transcribeStatusEl.textContent.indexOf("Błąd") !== 0) {
        setTranscribeStatus("Dyktowanie zatrzymane.");
      }
    };
    speechRecognition.onerror = function (event) {
      var code = String(event && event.error || "");
      var message = "Błąd dyktowania.";
      if (code === "not-allowed" || code === "service-not-allowed") message = "Brak zgody na mikrofon.";
      else if (code === "no-speech") message = "Nie wykryto mowy.";
      else if (code === "audio-capture") message = "Nie wykryto mikrofonu.";
      else if (code === "network") message = "Błąd usługi rozpoznawania mowy (network).";
      setTranscribeStatus(message);
    };
    speechRecognition.onresult = function (event) {
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
      setStatus("1 Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
      setVideoListStatus("");
      return false;
    }
    renderVideoList();
    var countMsg = videos.length ? ("Filmów w bazie: " + videos.length + ".") : "Brak filmów do wyboru.";
    setVideoListStatus(countMsg);
    return true;
  }

  async function reloadVideoContext(preloadedListData) {
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
      setStatus("2 Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
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
        setStatus("3 Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
        return;
      }
      applyAccessState(true);
      if (titleEl) titleEl.textContent = (data.video && data.video.tytul) ? data.video.tytul : "Wideo";
      editMode = !!data.edit;
      comments = Array.isArray(data.comments) ? data.comments : [];
      renderComments();
      loadYouTube(data.video.youtube_id || source);
      setStatus(editMode ? "Ładowanie odtwarzacza..." : "Tryb podglądu.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd ładowania.");
      comments = [];
      renderComments();
      if (addCommentBtn) addCommentBtn.hidden = true;
      if (formSection) formSection.hidden = true;
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
      setStatus("4 Brak dostępu do treści. Zaloguj się lub użyj poprawnego tokenu.");
    }
  }

  async function init() {
    initSpeechRecognition();
    initMobileMenuHandlers();
    scheduleVideoMenuButtons();

    var storedAuthor = getStoredAuthor();
    if (authorInput && !String(authorInput.value || "").trim() && storedAuthor) {
      authorInput.value = storedAuthor;
    }
    if (authorInput) {
      authorInput.addEventListener("change", function () { setStoredAuthor(authorInput.value || ""); });
    }

    if (authFormEl) authFormEl.addEventListener("submit", loginInline);
    if (authLogoutBtn) authLogoutBtn.addEventListener("click", logoutInline);
    if (authCloseBtn) authCloseBtn.addEventListener("click", closeAuthModal);
    if (authModalOverlayEl) authModalOverlayEl.addEventListener("click", closeAuthModal);
    if (addCloseBtn) addCloseBtn.addEventListener("click", closeAddModal);
    if (addModalOverlayEl) addModalOverlayEl.addEventListener("click", closeAddModal);
    document.addEventListener("keydown", handleAuthModalKeydown);
    document.addEventListener("keydown", handleAddModalKeydown);
    if (videoAddFormEl) videoAddFormEl.addEventListener("submit", submitVideoAdd);
    if (videoListSelectEl) videoListSelectEl.addEventListener("change", handleVideoSelectChange);

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

  if (addCommentBtn) addCommentBtn.addEventListener("click", showFormAtCurrentTime);
  if (cancelBtn) cancelBtn.addEventListener("click", hideForm);
  if (formEl) formEl.addEventListener("submit", submitComment);
  if (addCommentBtn) addCommentBtn.hidden = true;

  init();
})();

