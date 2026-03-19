(function () {
  "use strict";

  var API_URL = "/backend/video.php";
  var AUTH_API_URL = "/backend/video_auth.php";
  var ACCESS_API_URL = "/backend/access_token.php";
  var AUTHOR_STORAGE_KEY = "video_comment_author";
  var AUTHOR_COOKIE_KEY = "video_comment_author";
  var GDRIVE_MAP_STORAGE_KEY = "video_gdrive_map_v1";
  var params = new URLSearchParams(window.location.search);
  var source = (params.get("source") || "").trim();
  var gdriveIdParam = (params.get("gdrive_id") || "").trim();
  var accessToken = (params.get("vt") || "").trim();
  var rawEdit = (params.get("edit") || "").trim().toLowerCase();
  var editMode = rawEdit === "1" || rawEdit === "true" || rawEdit === "yes" || rawEdit === "on";

  var titleEl = document.getElementById("video-title");
  var statusEl = document.getElementById("video-status");
  var tokenInfoEl = document.getElementById("video-token-info");
  var accessMessageEl = document.getElementById("video-access-message");
  var playerWrapEl = document.getElementById("video-player-wrap");
  var ytPlayerEl = document.getElementById("yt-player");
  var drivePlayerEl = document.getElementById("drive-player");
  var drivePreviewEl = document.getElementById("drive-preview");
  var driveOpenExternalEl = document.getElementById("drive-open-external");
  var generatedAccessTokenBoxEl = document.getElementById("generated-access-token-box");
  var generatedAccessTokenUrlEl = document.getElementById("generated-access-token-url");
  var commentsSectionEl = document.getElementById("video-comments-section");
  var navDesktopEl = document.getElementById("navDesktop");
  var navMobileEl = document.getElementById("navMobile");
  var videoMenuDesktopSlotEl = document.getElementById("video-menu-controls-desktop");
  var videoMenuMobileSlotEl = document.getElementById("video-menu-controls-mobile");
  var videoMenuMobileUserSlotEl = document.getElementById("video-menu-user-mobile");
  var commentsListEl = document.getElementById("comments-list");
  var commentsEmptyEl = document.getElementById("comments-empty");
  var addCommentBtn = document.getElementById("add-comment-btn");
  var commentModalEl = document.getElementById("comment-modal");
  var commentModalOverlayEl = document.getElementById("comment-modal-overlay");
  var commentModalPanelEl = document.getElementById("comment-modal-panel");
  var commentModalCloseBtn = document.getElementById("comment-modal-close-btn");
  var reviewSummarySectionEl = document.getElementById("video-review-summary-section");
  var reviewSummaryMetaEl = document.getElementById("video-review-summary-meta");
  var reviewSummaryTabsEl = document.getElementById("video-review-summary-tabs");
  var reviewSummaryPrintBtn = document.getElementById("video-review-summary-print-btn");
  var reviewSummaryEmptyEl = document.getElementById("video-review-summary-empty");
  var reviewSummaryContentEl = document.getElementById("video-review-summary-content");
  var reviewModalEl = document.getElementById("review-modal");
  var reviewModalOverlayEl = document.getElementById("review-modal-overlay");
  var reviewModalPanelEl = document.getElementById("review-modal-panel");
  var reviewModalCloseBtn = document.getElementById("review-modal-close-btn");
  var reviewFormEl = document.getElementById("review-form");
  var reviewFormCategoriesEl = document.getElementById("review-form-categories");
  var reviewOverallNoteEl = document.getElementById("review-overall-note");
  var reviewSummaryIdEl = document.getElementById("review-summary-id");
  var reviewFormStatusEl = document.getElementById("review-form-status");
  var reviewSaveDraftBtn = document.getElementById("review-save-draft-btn");
  var reviewPublishBtn = document.getElementById("review-publish-btn");
  var reviewCancelBtn = document.getElementById("review-cancel-btn");
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
  var playerType = "";
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
  var reviewMenuTrigger = null;
  var reviewPreviouslyFocusedEl = null;
  var reviewDefinition = [];
  var reviewDefinitionMap = {};
  var reviewDraftSummary = null;
  var reviewPublishedSummary = null;
  var reviewPublishedSummaries = [];
  var activePublishedReviewId = null;

  var SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var speechRecognition = null;
  var isTranscribing = false;
  var mediaStream = null;
  var transcribeHeardSpeech = false;
  var transcribeSilenceTimer = null;
  var canEditCurrentSource = false;

  function roleTokens(rawRole) {
    return String(rawRole || "")
      .toLowerCase()
      .split(/[\s,;|]+/)
      .map(function (part) { return String(part || "").trim(); })
      .filter(function (part) { return !!part; });
  }

  function normalizeRole(rawRole) {
    var tokens = roleTokens(rawRole);
    if (tokens.indexOf("admin") >= 0) return "admin";
    if (tokens.indexOf("trener") >= 0 || tokens.indexOf("editor") >= 0) return "trener";
    if (tokens.indexOf("user") >= 0 || tokens.indexOf("viewer") >= 0) return "user";
    return String(rawRole || "").trim().toLowerCase();
  }

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
  }
  function setVideoListStatus(msg) {
    var statusEls = document.querySelectorAll("[data-video-list-status]");
    statusEls.forEach(function (node) { node.textContent = msg || ""; });
  }

  function getVideoPickerListEl() {
    return document.getElementById("video-picker-list");
  }

  function renderVideoPickerList() {
    var listEl = getVideoPickerListEl();
    if (!listEl) return;
    listEl.innerHTML = "";

    if (!videos.length) {
      var empty = document.createElement("p");
      empty.className = "video-picker-panel__empty";
      empty.textContent = hasContentAccess ? "Brak filmów w bazie." : "Brak dostępu.";
      listEl.appendChild(empty);
      return;
    }

    var fragment = document.createDocumentFragment();
    videos.forEach(function (video) {
      var value = String(video.youtube_id || "").trim();
      if (!value) return;
      var button = document.createElement("button");
      button.type = "button";
      button.className = "video-picker-item" + (value === source ? " is-active" : "");
      button.setAttribute("data-source", value);
      button.textContent = resolveVideoTitle(video);
      button.addEventListener("click", function () {
        if (value === source) {
          closeDesktopVideoMenuPanel(true);
          return;
        }
        if (accessInfo && accessInfo.token && accessInfo.token.resource_type === "video" &&
          accessInfo.token.resource_id && value !== accessInfo.token.resource_id) {
          setVideoListStatus("Ten token pozwala tylko na jeden film: " + accessInfo.token.resource_id);
          return;
        }
        setVideoListStatus("Przełączanie filmu...");
        closeDesktopVideoMenuPanel(false);
        if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
        window.location.href = buildVideoUrl(value);
      });
      fragment.appendChild(button);
    });
    listEl.appendChild(fragment);
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

  function hideGeneratedAccessToken() {
    if (generatedAccessTokenBoxEl) generatedAccessTokenBoxEl.hidden = true;
    if (generatedAccessTokenUrlEl) generatedAccessTokenUrlEl.value = "";
  }

  function getGenerateTokenTriggerElements() {
    return Array.prototype.slice.call(document.querySelectorAll("[data-generate-access-token-trigger]"));
  }

  function isTrainerUiAllowed() {
    var role = normalizeRole(authState && authState.role || "");
    var isEditorOrAdmin = role === "trener" || role === "admin";
    var roleAllowed = !!(authState && authState.logged_in === true && isEditorOrAdmin);
    return !!(roleAllowed && editMode === true && hasContentAccess && canEditCurrentSource && source);
  }

  function canShowAddVideoUi() {
    var role = normalizeRole(authState && authState.role || "");
    var isEditorOrAdmin = role === "trener" || role === "admin";
    return !!(authState && authState.logged_in === true && isEditorOrAdmin);
  }

  function updateGenerateTokenButtonVisibility() {
    var canShow = isTrainerUiAllowed();
    getGenerateTokenTriggerElements().forEach(function (buttonEl) {
      buttonEl.hidden = !canShow;
    });
    if (!canShow) hideGeneratedAccessToken();
    reorderMenuControls();
  }

  async function generateAccessTokenForCurrentVideo() {
    if (!source) return;
    if (!isTrainerUiAllowed()) {
      setStatus("Brak uprawnień do generowania tokenu dla tego filmu.");
      return;
    }
    var buttons = getGenerateTokenTriggerElements();
    buttons.forEach(function (buttonEl) { buttonEl.disabled = true; });
    setStatus("Generowanie tokenu...");
    try {
      var response = await fetch(ACCESS_API_URL + "?action=create", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          csrf_token: csrfToken,
          target: "video",
          scope: "view",
          resource_type: "video",
          resource_id: source,
          token_ttl_minutes: 1440,
          session_ttl_minutes: 720,
          note: "Szybki token z /video/play.php"
        })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok || !data.token) throw new Error(data.message || "Nie udało się wygenerować tokenu.");
      var tokenUrl = String(data.url || "").trim();
      if (!tokenUrl) tokenUrl = "/video/play.php?vt=" + encodeURIComponent(String(data.token));
      var absolute = tokenUrl.startsWith("http")
        ? tokenUrl
        : (window.location.origin + tokenUrl);
      if (generatedAccessTokenUrlEl) generatedAccessTokenUrlEl.value = absolute;
      if (generatedAccessTokenBoxEl) generatedAccessTokenBoxEl.hidden = false;
      setStatus("Token wygenerowany.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd generowania tokenu.");
      hideGeneratedAccessToken();
    } finally {
      buttons.forEach(function (buttonEl) { buttonEl.disabled = false; });
    }
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

  function isSourceKey(value) {
    return /^[A-Za-z0-9_-]{6,20}$/.test(String(value || "").trim());
  }

  function buildVideoUrl(youtubeId) {
    var next = new URLSearchParams(window.location.search);
    var sourceKey = String(youtubeId || "").trim();
    next.set("source", sourceKey);
    var meta = findVideoMetaBySource(sourceKey);
    var provider = String(meta && meta.provider || "").trim().toLowerCase();
    var providerVideoId = String(meta && meta.provider_video_id || "").trim();
    if (provider === "gdrive" && isDriveFileId(providerVideoId)) {
      next.set("gdrive_id", providerVideoId);
    } else if (isDriveSourceKey(sourceKey)) {
      var mappedId = getMappedGdriveId(sourceKey);
      if (mappedId) next.set("gdrive_id", mappedId);
      else next.delete("gdrive_id");
    } else {
      next.delete("gdrive_id");
    }
    if (editMode) next.set("edit", "1");
    else next.delete("edit");
    next.delete("vt");
    return "/video/play.php?" + next.toString();
  }

  function isDriveFileId(value) {
    return /^[A-Za-z0-9_-]{20,120}$/.test(String(value || "").trim());
  }

  function isDriveSourceKey(value) {
    return /^gd_[a-f0-9]{17}$/i.test(String(value || "").trim());
  }

  function readGdriveMap() {
    try {
      var raw = String(window.localStorage.getItem(GDRIVE_MAP_STORAGE_KEY) || "").trim();
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function writeGdriveMap(map) {
    try { window.localStorage.setItem(GDRIVE_MAP_STORAGE_KEY, JSON.stringify(map || {})); } catch (error) {}
  }

  function getMappedGdriveId(sourceKey) {
    var key = String(sourceKey || "").trim();
    if (!isDriveSourceKey(key)) return "";
    var map = readGdriveMap();
    var value = String(map[key] || "").trim();
    return isDriveFileId(value) ? value : "";
  }

  function rememberGdriveId(sourceKey, driveId) {
    var key = String(sourceKey || "").trim();
    var id = String(driveId || "").trim();
    if (!isDriveSourceKey(key) || !isDriveFileId(id)) return;
    var map = readGdriveMap();
    map[key] = id;
    writeGdriveMap(map);
  }

  function resolveVideoTitle(video) {
    var dbTitle = String(video && video.tytul || "").trim();
    return dbTitle || "Bez tytułu";
  }

  function buildReviewDefinitionMap(definition) {
    var map = {};
    (Array.isArray(definition) ? definition : []).forEach(function (category) {
      var categoryKey = String(category && category.key || "").trim();
      var categoryTitle = String(category && category.title || "").trim();
      var categoryPosition = Number(category && category.position || 0) || 0;
      var items = Array.isArray(category && category.items) ? category.items : [];
      items.forEach(function (item) {
        var key = String(item && item.item_key || "").trim();
        if (!key) return;
        map[key] = {
          item_key: key,
          label: String(item && item.label || "").trim(),
          category_key: categoryKey,
          category_title: categoryTitle,
          category_position: categoryPosition,
          position: Number(item && item.position || 0) || 0
        };
      });
    });
    return map;
  }

  function scoreTone(score) {
    var n = Number(score || 0);
    if (n <= 1) return "tone-low";
    if (n === 2) return "tone-mid";
    return "tone-high";
  }

  function scoreLabel(score) {
    var n = Number(score || 0);
    if (n <= 1) return "do poprawy";
    if (n === 2) return "średnio";
    return "mocna strona";
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
    var reviewOpen = isReviewModalOpen();
    return authOpen || addOpen || pickerOpen || commentOpen || reviewOpen;
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

  function isReviewModalOpen() {
    return !!(reviewModalEl && reviewModalEl.hidden === false);
  }

  function setReviewFormStatus(msg) {
    if (reviewFormStatusEl) reviewFormStatusEl.textContent = msg || "";
  }

  function setButtonLoading(buttonEl, loading) {
    if (!buttonEl) return;
    buttonEl.classList.toggle("is-loading", !!loading);
    buttonEl.disabled = !!loading;
  }

  function openReviewModal(triggerEl) {
    if (!reviewModalEl) return false;
    if (isReviewModalOpen()) return true;
    if (videoPickerModalEl && videoPickerModalEl.hidden === false) closeDesktopVideoMenuPanel(false);
    if (authModalEl && authModalEl.hidden === false) closeAuthModal();
    if (addModalEl && addModalEl.hidden === false) closeAddModal();
    if (isCommentModalOpen()) hideForm(false);
    reviewMenuTrigger = triggerEl || null;
    reviewPreviouslyFocusedEl = document.activeElement;
    reviewModalEl.hidden = false;
    document.body.classList.add("video-modal-open");
    return true;
  }

  function closeReviewModal(restoreFocus) {
    if (!reviewModalEl) return;
    reviewModalEl.hidden = true;
    if (!anyPrimaryModalOpen()) document.body.classList.remove("video-modal-open");
    if (restoreFocus) {
      var focusTarget = reviewMenuTrigger || reviewPreviouslyFocusedEl;
      if (focusTarget && typeof focusTarget.focus === "function") focusTarget.focus();
    }
    reviewMenuTrigger = null;
    reviewPreviouslyFocusedEl = null;
  }

  function renderReviewForm(definition) {
    if (!reviewFormCategoriesEl) return;
    reviewFormCategoriesEl.innerHTML = "";
    var categories = Array.isArray(definition) ? definition : [];
    var fragment = document.createDocumentFragment();
    categories.forEach(function (category) {
      var card = document.createElement("section");
      card.className = "review-form__category";
      card.setAttribute("data-category-key", String(category.key || ""));

      var title = document.createElement("h3");
      title.className = "review-form__category-title";
      title.textContent = String(category.title || "");
      card.appendChild(title);

      var itemsWrap = document.createElement("div");
      itemsWrap.className = "review-form__items";
      var items = Array.isArray(category.items) ? category.items : [];
      items.forEach(function (item) {
        var itemKey = String(item.item_key || "").trim();
        if (!itemKey) return;
        var row = document.createElement("div");
        row.className = "review-form__item";
        row.setAttribute("data-review-item", itemKey);

        var q = document.createElement("p");
        q.className = "review-form__question";
        q.textContent = String(item.label || "");
        row.appendChild(q);

        var scale = document.createElement("div");
        scale.className = "review-form__scale";
        [1, 2, 3].forEach(function (score) {
          var label = document.createElement("label");
          label.className = "review-form__score";
          label.setAttribute("data-score", String(score));

          var input = document.createElement("input");
          input.type = "radio";
          input.name = "review_" + itemKey;
          input.value = String(score);
          input.setAttribute("data-item-key", itemKey);
          input.addEventListener("change", function () {
            var group = scale.querySelectorAll(".review-form__score");
            group.forEach(function (el) { el.classList.remove("is-active"); });
            label.classList.add("is-active");
            row.classList.remove("review-form__item--missing");
            if (!countMissingReviewAnswers()) {
              setReviewFormStatus("");
            }
          });

          var text = document.createElement("span");
          text.textContent = String(score);
          label.appendChild(input);
          label.appendChild(text);
          scale.appendChild(label);
        });

        row.appendChild(scale);
        itemsWrap.appendChild(row);
      });

      card.appendChild(itemsWrap);
      fragment.appendChild(card);
    });
    reviewFormCategoriesEl.appendChild(fragment);
  }

  function setReviewFormValues(summary) {
    if (reviewSummaryIdEl) reviewSummaryIdEl.value = String(summary && summary.id || "");
    if (reviewOverallNoteEl) reviewOverallNoteEl.value = String(summary && summary.overall_note || "");
    if (!reviewFormEl) return;

    reviewFormEl.querySelectorAll(".review-form__item--missing").forEach(function (node) {
      node.classList.remove("review-form__item--missing");
    });
    reviewFormEl.querySelectorAll(".review-form__score").forEach(function (node) {
      node.classList.remove("is-active");
    });
    reviewFormEl.querySelectorAll('input[type="radio"][data-item-key]').forEach(function (input) {
      input.checked = false;
    });

    var answers = Array.isArray(summary && summary.answers) ? summary.answers : [];
    answers.forEach(function (answer) {
      var itemKey = String(answer && answer.item_key || "").trim();
      var score = String(answer && answer.score || "").trim();
      if (!itemKey || !score) return;
      var selector = 'input[type="radio"][data-item-key="' + itemKey + '"][value="' + score + '"]';
      var input = reviewFormEl.querySelector(selector);
      if (!input) return;
      input.checked = true;
      if (input.parentElement) input.parentElement.classList.add("is-active");
    });
  }

  function collectReviewAnswersFromForm() {
    if (!reviewFormEl) return [];
    var out = [];
    Object.keys(reviewDefinitionMap || {}).forEach(function (itemKey) {
      var checked = reviewFormEl.querySelector('input[type="radio"][data-item-key="' + itemKey + '"]:checked');
      if (!checked) return;
      var score = Number(checked.value || 0);
      if (!Number.isInteger(score) || score < 1 || score > 3) return;
      out.push({ item_key: itemKey, score: score });
    });
    return out;
  }

  function getReviewItemOrder(itemKey) {
    var def = reviewDefinitionMap && reviewDefinitionMap[itemKey] ? reviewDefinitionMap[itemKey] : null;
    if (!def) return 999999;
    var categoryPos = Number(def.category_position || 0) || 0;
    var itemPos = Number(def.position || 0) || 0;
    return categoryPos * 100 + itemPos;
  }

  function getMissingReviewItemKeys() {
    if (!reviewFormEl) return [];
    return Object.keys(reviewDefinitionMap || {})
      .filter(function (itemKey) {
        return !reviewFormEl.querySelector('input[type="radio"][data-item-key="' + itemKey + '"]:checked');
      })
      .sort(function (a, b) {
        var orderA = getReviewItemOrder(a);
        var orderB = getReviewItemOrder(b);
        if (orderA !== orderB) return orderA - orderB;
        return a.localeCompare(b);
      });
  }

  function countMissingReviewAnswers() {
    return getMissingReviewItemKeys().length;
  }

  function highlightMissingReviewItems(missingKeys) {
    if (!reviewFormEl) return null;
    reviewFormEl.querySelectorAll(".review-form__item--missing").forEach(function (node) {
      node.classList.remove("review-form__item--missing");
    });
    var firstRow = null;
    (Array.isArray(missingKeys) ? missingKeys : []).forEach(function (itemKey) {
      var row = reviewFormEl.querySelector('[data-review-item="' + itemKey + '"]');
      if (!row) return;
      row.classList.add("review-form__item--missing");
      if (!firstRow) firstRow = row;
    });
    return firstRow;
  }

  function focusFirstMissingReviewItem(missingKeys) {
    if (!reviewFormEl) return false;
    var firstKey = Array.isArray(missingKeys) && missingKeys.length ? missingKeys[0] : "";
    if (!firstKey) return false;
    var row = reviewFormEl.querySelector('[data-review-item="' + firstKey + '"]');
    if (!row) return false;
    if (typeof row.scrollIntoView === "function") {
      row.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    var input = row.querySelector('input[type="radio"]');
    if (input && typeof input.focus === "function") input.focus();
    return true;
  }

  function notifyMissingReviewAnswers() {
    var missingKeys = getMissingReviewItemKeys();
    if (!missingKeys.length) {
      highlightMissingReviewItems([]);
      return false;
    }
    highlightMissingReviewItems(missingKeys);
    setReviewFormStatus("Uzupełnij wszystkie oceny (brakuje: " + missingKeys.length + ").");
    focusFirstMissingReviewItem(missingKeys);
    return true;
  }

  function normalizePublishedReviewSummaries(input) {
    var list = [];
    if (Array.isArray(input)) {
      list = input.slice();
    } else if (input && typeof input === "object") {
      list = [input];
    }
    return list.filter(function (row) {
      return row && Array.isArray(row.categories) && row.categories.length;
    });
  }

  function getActivePublishedSummary() {
    if (!reviewPublishedSummaries.length) return null;
    if (activePublishedReviewId !== null) {
      var fromState = reviewPublishedSummaries.find(function (row) {
        return Number(row && row.id || 0) === Number(activePublishedReviewId);
      });
      if (fromState) return fromState;
    }
    return reviewPublishedSummaries[0] || null;
  }

  function formatSummaryTabLabel(summary) {
    var reviewerEmail = String(summary && summary.reviewer_email || "").trim() || "-";
    return "Podsumowanie trenera " + reviewerEmail;
  }

  function buildReviewPrintUrl(summary) {
    if (!summary || !source) return "";
    var paramsPrint = new URLSearchParams();
    paramsPrint.set("source", String(source));
    paramsPrint.set("review_id", String(Number(summary.id || 0)));
    return "/video/review-print.php?" + paramsPrint.toString();
  }

  function renderReviewSummaryTabs() {
    if (!reviewSummaryTabsEl) return;
    reviewSummaryTabsEl.innerHTML = "";
    var items = reviewPublishedSummaries;
    if (!items.length) {
      reviewSummaryTabsEl.hidden = true;
      return;
    }
    reviewSummaryTabsEl.hidden = false;
    var currentId = Number(activePublishedReviewId || (items[0] && items[0].id) || 0);
    var fragment = document.createDocumentFragment();
    items.forEach(function (summary) {
      var id = Number(summary && summary.id || 0);
      var button = document.createElement("button");
      button.type = "button";
      button.className = "video-review-summary__tab" + (id === currentId ? " is-active" : "");
      button.setAttribute("data-review-summary-id", String(id));
      button.textContent = formatSummaryTabLabel(summary);
      button.addEventListener("click", function () {
        activePublishedReviewId = id;
        renderReviewSummary(reviewPublishedSummaries);
      });
      fragment.appendChild(button);
    });
    reviewSummaryTabsEl.appendChild(fragment);
  }

  function renderReviewSummary(input) {
    if (!reviewSummarySectionEl || !reviewSummaryContentEl || !reviewSummaryEmptyEl || !reviewSummaryMetaEl) return;
    reviewSummarySectionEl.hidden = !hasContentAccess;
    reviewSummaryContentEl.innerHTML = "";

    reviewPublishedSummaries = normalizePublishedReviewSummaries(input);
    if (reviewPublishedSummaries.length && activePublishedReviewId === null) {
      activePublishedReviewId = Number(reviewPublishedSummaries[0].id || 0) || null;
    }
    var summary = getActivePublishedSummary();
    renderReviewSummaryTabs();

    if (!summary) {
      reviewSummaryEmptyEl.hidden = false;
      reviewSummaryMetaEl.textContent = "";
      if (reviewSummaryPrintBtn) {
        reviewSummaryPrintBtn.hidden = true;
        reviewSummaryPrintBtn.onclick = null;
      }
      return;
    }
    reviewSummaryEmptyEl.hidden = true;

    var total = Number(summary.total_score || 0);
    var max = Number(summary.max_score || 0);
    var percent = max > 0 ? Math.round((total / max) * 100) : 0;
    var publishedAt = String(summary.published_at || "").trim();
    var reviewerEmail = String(summary.reviewer_email || "").trim() || "-";
    reviewSummaryMetaEl.textContent = "Trener: " + reviewerEmail + (publishedAt ? (" | Opublikowano: " + publishedAt) : "");

    if (reviewSummaryPrintBtn) {
      var printUrl = buildReviewPrintUrl(summary);
      reviewSummaryPrintBtn.hidden = !printUrl;
      reviewSummaryPrintBtn.onclick = printUrl ? function () {
        window.open(printUrl, "_blank", "noopener,noreferrer");
      } : null;
    }

    var overall = document.createElement("section");
    overall.className = "video-review-summary__overall";
    overall.innerHTML =
      '<p class="video-review-summary__overall-title">Wynik globalny</p>' +
      '<div class="video-review-summary__overall-line"><span>' + total + ' / ' + max + '</span><span>' + percent + '%</span></div>' +
      '<div class="video-review-summary__bar"><div class="video-review-summary__bar-fill" style="width:' + Math.max(0, Math.min(100, percent)) + '%"></div></div>';
    reviewSummaryContentEl.appendChild(overall);

    var cardsWrap = document.createElement("div");
    cardsWrap.className = "video-review-summary__categories";
    summary.categories.forEach(function (category) {
      var card = document.createElement("article");
      card.className = "video-review-summary__card";
      var catAvg = Number(category && category.avg_score || 0);
      var catPercent = Math.round((catAvg / 3) * 100);
      var title = '<h3 class="video-review-summary__card-title">' + escapeHtml(String(category && category.title || "")) + ' · ' + catAvg.toFixed(2) + '/3</h3>';
      var bar = '<div class="video-review-summary__bar"><div class="video-review-summary__bar-fill" style="width:' + Math.max(0, Math.min(100, catPercent)) + '%"></div></div>';
      card.innerHTML = title + bar;

      var items = Array.isArray(category && category.items) ? category.items : [];
      items.forEach(function (item) {
        var row = document.createElement("div");
        row.className = "video-review-summary__item";
        var score = Number(item && item.score || 0);
        var scorePct = Math.round((score / 3) * 100);
        row.innerHTML =
          '<span class="video-review-summary__item-label">' + escapeHtml(String(item && item.label || "")) + '</span>' +
          '<div class="video-review-summary__item-line">' +
            '<div class="video-review-summary__bar"><div class="video-review-summary__bar-fill" style="width:' + Math.max(0, Math.min(100, scorePct)) + '%"></div></div>' +
            '<span class="video-review-summary__item-score ' + scoreTone(score) + '">' + score + '/3 · ' + scoreLabel(score) + '</span>' +
          '</div>';
        card.appendChild(row);
      });
      cardsWrap.appendChild(card);
    });
    reviewSummaryContentEl.appendChild(cardsWrap);

    var note = String(summary.overall_note || "").trim();
    if (note) {
      var noteBox = document.createElement("div");
      noteBox.className = "video-review-summary__note";
      noteBox.textContent = note;
      reviewSummaryContentEl.appendChild(noteBox);
    }
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
    window.history.replaceState({}, "", "/video/play.php" + (query ? ("?" + query) : ""));
  }

  function syncAuthFromAccess(access) {
    if (!access || !access.user) return;
    authState = {
      logged_in: !!access.user.logged_in,
      user_id: access.user.user_id || null,
      email: access.user.email || null,
      role: normalizeRole(access.user.role || "")
    };
  }

  function roleLabel(role) {
    var normalized = normalizeRole(role || "");
    if (normalized === "admin") return "admin";
    if (normalized === "trener") return "trener";
    if (normalized === "user") return "user";
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
    if (reviewSummarySectionEl) reviewSummarySectionEl.hidden = !hasContentAccess;
    if (!hasContentAccess) {
      canEditCurrentSource = false;
      destroyCurrentPlayer();
      comments = [];
      reviewDraftSummary = null;
      reviewPublishedSummary = null;
      reviewPublishedSummaries = [];
      activePublishedReviewId = null;
      renderComments();
      renderReviewSummary(null);
      getVideoListSelectElements().forEach(function (selectEl) {
        selectEl.innerHTML = "<option value=\"\">Brak dostępu</option>";
      });
      if (addCommentBtn) addCommentBtn.hidden = true;
      hideForm(false);
      closeReviewModal(false);
      updateGenerateTokenButtonVisibility();
      updateReviewMenuButtons();
    }
    if (hasContentAccess) {
      updateGenerateTokenButtonVisibility();
      updateReviewMenuButtons();
    }
  }

  function updateAuthMenuButtons() {
    var desktopBtn = document.getElementById("video-auth-trigger-desktop");
    var mobileBtn = document.getElementById("video-auth-trigger-mobile");
    var desktopIdentity = document.getElementById("video-auth-identity-desktop");
    var mobileIdentity = document.getElementById("video-auth-identity-mobile");
    var desktopLogout = document.getElementById("video-auth-logout-desktop");
    var mobileLogout = document.getElementById("video-auth-logout-mobile");
    var logged = !!(authState && authState.logged_in);
    var normalizedRole = normalizeRole(authState && authState.role || "");
    var who = String(authState && authState.email || "").trim() || "Zalogowany";
    var label = normalizedRole ? (who + " (" + normalizedRole + ")") : who;
    if (desktopBtn) {
      desktopBtn.textContent = "Logowanie";
      desktopBtn.hidden = logged;
    }
    if (mobileBtn) {
      mobileBtn.textContent = "Logowanie";
      mobileBtn.hidden = logged;
    }
    if (desktopIdentity) {
      desktopIdentity.textContent = label;
      desktopIdentity.hidden = !logged;
    }
    if (mobileIdentity) {
      mobileIdentity.textContent = label;
      mobileIdentity.hidden = !logged;
    }
    if (desktopLogout) desktopLogout.hidden = !logged;
    if (mobileLogout) mobileLogout.hidden = !logged;
    reorderMenuControls();
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
    var canAdd = canCurrentUserAddVideo() && canShowAddVideoUi();
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

  function reorderMenuControls() {
    var desktopRoot = videoMenuDesktopSlotEl || navDesktopEl;
    var mobileRoot = videoMenuMobileSlotEl || navMobileEl;
    var mobileUserRoot = videoMenuMobileUserSlotEl || mobileRoot;
    if (desktopRoot) {
      [
        "video-picker-desktop",
        "video-add-trigger-desktop",
        "video-generate-token-trigger-desktop",
        "video-review-summary-trigger-desktop",
        "video-auth-trigger-desktop",
        "video-auth-identity-desktop",
        "video-auth-logout-desktop"
      ].forEach(function (id) {
        var node = document.getElementById(id);
        if (node && node.parentElement === desktopRoot) desktopRoot.appendChild(node);
      });
    }
    if (mobileRoot) {
      [
        "video-picker-mobile",
        "video-add-trigger-mobile",
        "video-generate-token-trigger-mobile",
        "video-review-summary-trigger-mobile",
        "video-auth-trigger-mobile"
      ].forEach(function (id) {
        var node = document.getElementById(id);
        if (node && node.parentElement === mobileRoot) mobileRoot.appendChild(node);
      });
    }
    if (mobileUserRoot) {
      [
        "video-auth-identity-mobile",
        "video-auth-logout-mobile"
      ].forEach(function (id) {
        var node = document.getElementById(id);
        if (node && node.parentElement === mobileUserRoot) mobileUserRoot.appendChild(node);
      });
    }
  }

  function getReviewMenuTriggers() {
    return Array.prototype.slice.call(document.querySelectorAll("[data-review-summary-trigger]"));
  }

  function updateReviewMenuButtons() {
    var canShow = isTrainerUiAllowed();
    getReviewMenuTriggers().forEach(function (buttonEl) {
      buttonEl.hidden = !canShow;
    });
    if (!canShow && isReviewModalOpen()) closeReviewModal(false);
    reorderMenuControls();
  }

  async function fetchReviewFormData() {
    var url = API_URL + "?action=load_review_form&source=" + encodeURIComponent(source);
    var response = await fetch(url, { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się pobrać formularza podsumowania.");
    return data;
  }

  async function openReviewModalFromMenu(event) {
    if (event) {
      event.preventDefault();
      reviewMenuTrigger = event.currentTarget || null;
    }
    if (!isTrainerUiAllowed()) return;
    closeDesktopVideoMenuPanel(false);
    if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
    setReviewFormStatus("Ładowanie formularza...");
    setButtonLoading(reviewSaveDraftBtn, false);
    setButtonLoading(reviewPublishBtn, false);
    try {
      var data = await fetchReviewFormData();
      reviewDefinition = Array.isArray(data.definition) ? data.definition : [];
      reviewDefinitionMap = buildReviewDefinitionMap(reviewDefinition);
      reviewDraftSummary = data.review_summary_draft || null;
      reviewPublishedSummary = data.review_summary_published || null;
      reviewPublishedSummaries = normalizePublishedReviewSummaries(data.review_summaries_published || reviewPublishedSummary);
      activePublishedReviewId = reviewPublishedSummaries.length ? Number(reviewPublishedSummaries[0].id || 0) : null;
      renderReviewForm(reviewDefinition);
      setReviewFormValues(reviewDraftSummary || reviewPublishedSummary || null);
      renderReviewSummary(reviewPublishedSummaries);
      openReviewModal(reviewMenuTrigger);
      if (reviewDraftSummary || reviewPublishedSummary) {
        if (!notifyMissingReviewAnswers()) setReviewFormStatus("");
      } else {
        setReviewFormStatus("");
        var firstInput = reviewFormEl ? reviewFormEl.querySelector('input[type="radio"]') : null;
        if (firstInput && typeof firstInput.focus === "function") firstInput.focus();
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd ładowania podsumowania.");
    }
  }

  async function saveReviewDraft() {
    if (!source || !reviewFormEl) return null;
    var payload = {
      source: source,
      summary_id: Number(reviewSummaryIdEl && reviewSummaryIdEl.value || 0) || undefined,
      overall_note: String(reviewOverallNoteEl && reviewOverallNoteEl.value || "").trim(),
      answers: collectReviewAnswersFromForm(),
      csrf_token: csrfToken
    };
    var response = await fetch(API_URL + "?action=save_review_draft", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload)
    });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się zapisać szkicu.");
    if (Array.isArray(data.definition)) {
      reviewDefinition = data.definition;
      reviewDefinitionMap = buildReviewDefinitionMap(reviewDefinition);
    }
    reviewDraftSummary = data.review_summary_draft || reviewDraftSummary;
    setReviewFormValues(reviewDraftSummary);
    return data;
  }

  async function handleReviewSaveDraft() {
    setReviewFormStatus("Zapisywanie szkicu...");
    setButtonLoading(reviewSaveDraftBtn, true);
    setButtonLoading(reviewPublishBtn, false);
    try {
      await saveReviewDraft();
      setReviewFormStatus("Szkic zapisany.");
    } catch (error) {
      setReviewFormStatus(error instanceof Error ? error.message : "Błąd zapisu szkicu.");
    } finally {
      setButtonLoading(reviewSaveDraftBtn, false);
    }
  }

  async function handleReviewPublish() {
    if (notifyMissingReviewAnswers()) return;
    setReviewFormStatus("Publikowanie podsumowania...");
    setButtonLoading(reviewPublishBtn, true);
    setButtonLoading(reviewSaveDraftBtn, false);
    try {
      var saveData = await saveReviewDraft();
      var summaryId = Number(saveData && saveData.review_summary_draft && saveData.review_summary_draft.id || 0);
      if (summaryId <= 0) throw new Error("Brak identyfikatora szkicu do publikacji.");

      var response = await fetch(API_URL + "?action=publish_review", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          source: source,
          summary_id: summaryId,
          csrf_token: csrfToken
        })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się opublikować podsumowania.");
      reviewPublishedSummary = data.review_summary_published || reviewPublishedSummary;
      reviewPublishedSummaries = normalizePublishedReviewSummaries(data.review_summaries_published || reviewPublishedSummary);
      activePublishedReviewId = reviewPublishedSummaries.length ? Number(reviewPublishedSummaries[0].id || 0) : null;
      reviewDraftSummary = null;
      renderReviewSummary(reviewPublishedSummaries);
      setReviewFormStatus("Podsumowanie opublikowane.");
      closeReviewModal(true);
    } catch (error) {
      setReviewFormStatus(error instanceof Error ? error.message : "Błąd publikacji.");
    } finally {
      setButtonLoading(reviewPublishBtn, false);
    }
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

    var list = document.createElement("div");
    list.id = "video-picker-list";
    list.className = "video-picker-panel__list";

    var status = document.createElement("p");
    status.id = "video-list-status-modal";
    status.className = "video-picker-panel__status";
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    status.setAttribute("data-video-list-status", "modal");

    panel.appendChild(closeBtn);
    panel.appendChild(heading);
    panel.appendChild(list);
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
    renderVideoPickerList();
    var active = videoPickerModalEl ? videoPickerModalEl.querySelector(".video-picker-item.is-active") : null;
    if (active && typeof active.focus === "function") active.focus();
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
    var mobileUserRoot = videoMenuMobileUserSlotEl || mobileRoot;

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

    var desktopIdentity = document.getElementById("video-auth-identity-desktop");
    if (!desktopIdentity) {
      desktopIdentity = document.createElement("span");
      desktopIdentity.id = "video-auth-identity-desktop";
      desktopIdentity.className = "video-menu-identity";
      desktopIdentity.hidden = true;
      desktopRoot.appendChild(desktopIdentity);
    }

    var mobileIdentity = document.getElementById("video-auth-identity-mobile");
    if (!mobileIdentity) {
      mobileIdentity = document.createElement("div");
      mobileIdentity.id = "video-auth-identity-mobile";
      mobileIdentity.className = "video-mobile-identity";
      mobileIdentity.hidden = true;
      mobileUserRoot.appendChild(mobileIdentity);
    }

    var desktopLogoutBtn = document.getElementById("video-auth-logout-desktop");
    if (!desktopLogoutBtn) {
      desktopLogoutBtn = document.createElement("button");
      desktopLogoutBtn.id = "video-auth-logout-desktop";
      desktopLogoutBtn.type = "button";
      desktopLogoutBtn.className = "video-menu-btn video-menu-btn--secondary";
      desktopLogoutBtn.textContent = "Wyloguj";
      desktopLogoutBtn.hidden = true;
      desktopLogoutBtn.addEventListener("click", function () {
        closeDesktopVideoMenuPanel(false);
        logoutInline();
      });
      desktopRoot.appendChild(desktopLogoutBtn);
    }

    var mobileLogoutBtn = document.getElementById("video-auth-logout-mobile");
    if (!mobileLogoutBtn) {
      mobileLogoutBtn = document.createElement("button");
      mobileLogoutBtn.id = "video-auth-logout-mobile";
      mobileLogoutBtn.type = "button";
      mobileLogoutBtn.className = "video-mobile-nav-btn";
      mobileLogoutBtn.textContent = "Wyloguj";
      mobileLogoutBtn.hidden = true;
      mobileLogoutBtn.addEventListener("click", function () {
        if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
        logoutInline();
      });
      mobileUserRoot.appendChild(mobileLogoutBtn);
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

    var desktopTokenBtn = document.getElementById("video-generate-token-trigger-desktop");
    if (!desktopTokenBtn) {
      desktopTokenBtn = document.createElement("button");
      desktopTokenBtn.id = "video-generate-token-trigger-desktop";
      desktopTokenBtn.type = "button";
      desktopTokenBtn.className = "video-menu-btn video-menu-btn--secondary";
      desktopTokenBtn.textContent = "Wygeneruj token dostępu";
      desktopTokenBtn.hidden = true;
      desktopTokenBtn.setAttribute("data-generate-access-token-trigger", "1");
      desktopTokenBtn.addEventListener("click", function () {
        closeDesktopVideoMenuPanel(false);
        generateAccessTokenForCurrentVideo();
      });
      desktopRoot.appendChild(desktopTokenBtn);
    }

    var mobileTokenBtn = document.getElementById("video-generate-token-trigger-mobile");
    if (!mobileTokenBtn) {
      mobileTokenBtn = document.createElement("button");
      mobileTokenBtn.id = "video-generate-token-trigger-mobile";
      mobileTokenBtn.type = "button";
      mobileTokenBtn.className = "video-mobile-nav-btn";
      mobileTokenBtn.textContent = "Wygeneruj token dostępu";
      mobileTokenBtn.hidden = true;
      mobileTokenBtn.setAttribute("data-generate-access-token-trigger", "1");
      mobileTokenBtn.addEventListener("click", function () {
        if (typeof closeMobileMenuFn === "function") closeMobileMenuFn();
        generateAccessTokenForCurrentVideo();
      });
      mobileRoot.appendChild(mobileTokenBtn);
    }

    var desktopReviewBtn = document.getElementById("video-review-summary-trigger-desktop");
    if (!desktopReviewBtn) {
      desktopReviewBtn = document.createElement("button");
      desktopReviewBtn.id = "video-review-summary-trigger-desktop";
      desktopReviewBtn.type = "button";
      desktopReviewBtn.className = "video-menu-btn video-menu-btn--secondary";
      desktopReviewBtn.textContent = "Podsumuj nagranie";
      desktopReviewBtn.hidden = true;
      desktopReviewBtn.setAttribute("data-review-summary-trigger", "1");
      desktopReviewBtn.addEventListener("click", openReviewModalFromMenu);
      desktopRoot.appendChild(desktopReviewBtn);
    }

    var mobileReviewBtn = document.getElementById("video-review-summary-trigger-mobile");
    if (!mobileReviewBtn) {
      mobileReviewBtn = document.createElement("button");
      mobileReviewBtn.id = "video-review-summary-trigger-mobile";
      mobileReviewBtn.type = "button";
      mobileReviewBtn.className = "video-mobile-nav-btn";
      mobileReviewBtn.textContent = "Podsumuj nagranie";
      mobileReviewBtn.hidden = true;
      mobileReviewBtn.setAttribute("data-review-summary-trigger", "1");
      mobileReviewBtn.addEventListener("click", openReviewModalFromMenu);
      mobileRoot.appendChild(mobileReviewBtn);
    }

    updateAuthMenuButtons();
    updateVideoAddMenuButtons();
    updateGenerateTokenButtonVisibility();
    updateReviewMenuButtons();
    reorderMenuControls();
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

  function handleReviewModalKeydown(event) {
    if (!isReviewModalOpen()) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeReviewModal(true);
      return;
    }
    if (event.key !== "Tab") return;
    var focusables = getFocusableWithin(reviewModalPanelEl || reviewModalEl);
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
    updateGenerateTokenButtonVisibility();
    updateReviewMenuButtons();
  }

  async function fetchAuthStatus() {
    var response = await fetch(AUTH_API_URL + "?action=status", { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) throw new Error(data.message || "Nie udało się pobrać statusu logowania.");
    authState = data.user || { logged_in: false, user_id: null, email: null, role: null };
    authState.role = normalizeRole(authState.role || "");
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
      authState.role = normalizeRole(authState.role || "");
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
      authState.role = normalizeRole(authState.role || "");
      csrfToken = String(data.csrf_token || "");
      if (authCsrfEl) authCsrfEl.value = csrfToken;
      window.location.replace("/video/");
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
      setVideoAddStatus("Wklej link YouTube lub Google Drive.");
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
    renderVideoPickerList();
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
    var target = Math.max(0, Number(seconds) || 0);
    if (playerType === "youtube" && typeof player.seekTo === "function") {
      player.seekTo(target, true);
      if (typeof player.playVideo === "function") player.playVideo();
      return;
    }
    if (playerType === "html5" && typeof player.currentTime !== "undefined") {
      try { player.currentTime = target; } catch (error) {}
      if (typeof player.play === "function") player.play().catch(function () {});
    }
  }

  function clearPauseAutoOpenCandidate() {
    if (pauseAutoOpenTimer) {
      window.clearTimeout(pauseAutoOpenTimer);
      pauseAutoOpenTimer = null;
    }
    pauseAutoOpenCandidate = null;
  }

  function readCurrentPlayerTime() {
    if (!player) return 0;
    var value = 0;
    if (playerType === "youtube" && typeof player.getCurrentTime === "function") {
      value = Number(player.getCurrentTime() || 0);
    } else if (playerType === "html5" && typeof player.currentTime !== "undefined") {
      value = Number(player.currentTime || 0);
    }
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
    var seconds = Math.max(0, Math.floor(readCurrentPlayerTime() || 0));
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

  function setPlayerSurface(type) {
    if (ytPlayerEl) ytPlayerEl.hidden = type !== "youtube";
    if (drivePlayerEl) drivePlayerEl.hidden = type !== "html5";
    if (drivePreviewEl) drivePreviewEl.hidden = type !== "drive_preview";
  }

  function setDriveExternalLink(video) {
    if (!driveOpenExternalEl) return;
    var driveId = resolveDriveFileId(video || {});
    if (!driveId) {
      driveOpenExternalEl.hidden = true;
      driveOpenExternalEl.removeAttribute("href");
      return;
    }
    driveOpenExternalEl.href = "https://drive.google.com/file/d/" + encodeURIComponent(driveId) + "/view";
    driveOpenExternalEl.hidden = false;
  }

  function resetPlayerState() {
    playerReady = false;
    lastPlayerState = -1;
    clearPauseAutoOpenCandidate();
  }

  function destroyCurrentPlayer() {
    clearPauseAutoOpenCandidate();
    if (playerType === "youtube" && player && typeof player.destroy === "function") {
      try { player.destroy(); } catch (error) {}
    }
    if (drivePlayerEl) {
      try { drivePlayerEl.pause(); } catch (error) {}
      drivePlayerEl.removeAttribute("src");
      drivePlayerEl.load();
    }
    if (drivePreviewEl) drivePreviewEl.removeAttribute("src");
    if (driveOpenExternalEl) {
      driveOpenExternalEl.hidden = true;
      driveOpenExternalEl.removeAttribute("href");
    }
    player = null;
    playerType = "";
    resetPlayerState();
  }

  function handlePlayerStateChange(nextState) {
    var prevState = lastPlayerState;
    lastPlayerState = Number(nextState);

    if (!editMode) {
      if (addCommentBtn) addCommentBtn.hidden = true;
      clearPauseAutoOpenCandidate();
      return;
    }
    if (lastPlayerState === 1) {
      if (addCommentBtn && !isCommentModalOpen()) addCommentBtn.hidden = true;
      clearPauseAutoOpenCandidate();
      return;
    }
    if (lastPlayerState === 3 || lastPlayerState === 5) {
      clearPauseAutoOpenCandidate();
      return;
    }
    if (lastPlayerState === 2) {
      if (addCommentBtn) addCommentBtn.hidden = false;
      if (prevState === 1 && !isCommentModalOpen()) scheduleAutoCommentOpenOnPause();
      return;
    }
    if (lastPlayerState === 0 || lastPlayerState === -1) clearPauseAutoOpenCandidate();
  }

  function ensureDrivePlayerBindings() {
    if (!drivePlayerEl || drivePlayerEl.dataset.boundPlayerState === "1") return;

    drivePlayerEl.addEventListener("play", function () {
      if (playerType !== "html5") return;
      handlePlayerStateChange(1);
    });
    drivePlayerEl.addEventListener("pause", function () {
      if (playerType !== "html5") return;
      handlePlayerStateChange(2);
    });
    drivePlayerEl.addEventListener("seeking", function () {
      if (playerType !== "html5") return;
      handlePlayerStateChange(3);
    });
    drivePlayerEl.addEventListener("waiting", function () {
      if (playerType !== "html5") return;
      handlePlayerStateChange(3);
    });
    drivePlayerEl.addEventListener("ended", function () {
      if (playerType !== "html5") return;
      handlePlayerStateChange(0);
    });
    drivePlayerEl.addEventListener("loadedmetadata", function () {
      if (playerType !== "html5") return;
      playerReady = true;
      setStatus("Gotowe");
    });

    drivePlayerEl.dataset.boundPlayerState = "1";
  }

  function resolveDriveFileId(video) {
    var direct = String(video && video.source_url || "").trim();
    direct = direct
      .replace(/&amp;/g, "&")
      .replace(/\\\//g, "/")
      .replace(/%2F/gi, "/");

    var driveId = String(video && video.provider_video_id || "").trim();
    if (!driveId && direct) {
      var matchPath = direct.match(/\/file\/d\/([A-Za-z0-9_-]{20,120})/i);
      var matchQuery = direct.match(/[?&]id=([A-Za-z0-9_-]{20,120})/i);
      if (matchPath && matchPath[1]) driveId = matchPath[1];
      else if (matchQuery && matchQuery[1]) driveId = matchQuery[1];
    }
    if (!driveId && direct) {
      try {
        var parsed = new URL(direct, window.location.origin);
        var host = String(parsed.hostname || "").toLowerCase();
        if (host.indexOf("drive.google.com") !== -1 || host.indexOf("docs.google.com") !== -1) {
          driveId = parsed.searchParams.get("id") || "";
          if (!driveId) {
            var parts = String(parsed.pathname || "").split("/").filter(Boolean);
            var dIndex = parts.indexOf("d");
            if (dIndex >= 0 && parts[dIndex + 1]) driveId = parts[dIndex + 1];
          }
        }
      } catch (error) {}
    }

    if (!driveId && isDriveSourceKey(source) && isDriveFileId(gdriveIdParam)) {
      driveId = gdriveIdParam;
    }
    if (!driveId && isDriveSourceKey(source)) {
      driveId = getMappedGdriveId(source);
    }
    if (!driveId) return "";
    rememberGdriveId(source, driveId);
    return driveId;
  }

  function resolveDrivePlayableUrl(video) {
    var direct = String(video && video.source_url || "").trim();
    direct = direct
      .replace(/&amp;/g, "&")
      .replace(/\\\//g, "/")
      .replace(/%2F/gi, "/");
    if (direct && direct.indexOf("/uc?export=download") !== -1) return direct;
    var driveId = resolveDriveFileId(video);
    if (!driveId) return "";
    return "https://drive.google.com/uc?export=download&id=" + encodeURIComponent(driveId);
  }

  function resolveDrivePreviewUrl(video) {
    var driveId = resolveDriveFileId(video);
    if (!driveId) return "";
    return "https://drive.google.com/file/d/" + encodeURIComponent(driveId) + "/preview";
  }

  function isLikelyDriveVideo(video) {
    var provider = String(video && video.provider || "").trim().toLowerCase();
    if (provider === "gdrive") return true;
    var sourceKey = String(video && video.youtube_id || source || "").trim();
    if (isDriveSourceKey(sourceKey)) return true;
    if (isDriveSourceKey(sourceKey) && isDriveFileId(gdriveIdParam)) return true;
    var sourceUrl = String(video && video.source_url || "").toLowerCase();
    return sourceUrl.indexOf("drive.google.com") !== -1 || sourceUrl.indexOf("docs.google.com") !== -1;
  }

  function findVideoMetaBySource(sourceKey) {
    var key = String(sourceKey || "").trim();
    if (!key || !Array.isArray(videos)) return null;
    for (var i = 0; i < videos.length; i += 1) {
      var item = videos[i] || {};
      if (String(item.youtube_id || "").trim() === key) return item;
    }
    return null;
  }

  function loadDriveVideo(video) {
    if (!drivePlayerEl) {
      setStatus("Brak komponentu odtwarzacza Google Drive.");
      return;
    }

    var playableUrl = resolveDrivePlayableUrl(video);
    if (!playableUrl) {
      var fallbackMeta = findVideoMetaBySource(source);
      if (fallbackMeta) playableUrl = resolveDrivePlayableUrl(fallbackMeta);
    }
    if (!playableUrl) {
      if (window.console && typeof window.console.warn === "function") {
        window.console.warn("Drive metadata missing", {
          source: source,
          video: video,
          matched: findVideoMetaBySource(source)
        });
      }
      setStatus("Brak poprawnego adresu źródła dla Google Drive.");
      setDriveExternalLink(video || findVideoMetaBySource(source) || {});
      return;
    }

    var fallbackToPreview = function (reason) {
      var previewUrl = resolveDrivePreviewUrl(video || {});
      if (!previewUrl) {
        var fallbackMeta = findVideoMetaBySource(source);
        if (fallbackMeta) previewUrl = resolveDrivePreviewUrl(fallbackMeta);
      }
      if (!previewUrl || !drivePreviewEl) {
        setStatus("Brak poprawnego adresu źródła dla Google Drive.");
        setDriveExternalLink(video || findVideoMetaBySource(source) || {});
        return;
      }
      if (window.console && typeof window.console.warn === "function") {
        window.console.warn("Drive HTML5 fallback -> preview", { reason: reason || "unknown", source: source });
      }
      setPlayerSurface("drive_preview");
      playerType = "drive_preview";
      player = null;
      resetPlayerState();
      if (addCommentBtn) addCommentBtn.hidden = true;
      drivePreviewEl.src = previewUrl;
      setStatus("Odtwarzanie przez Google Drive preview (fallback).");
      setDriveExternalLink(video || findVideoMetaBySource(source) || {});
    };

    if (playerType === "youtube" || !player) destroyCurrentPlayer();
    setPlayerSurface("html5");
    ensureDrivePlayerBindings();
    player = drivePlayerEl;
    playerType = "html5";
    resetPlayerState();
    var resolved = false;
    var resolveTimer = 0;
    var onReady = function () {
      resolved = true;
      if (resolveTimer) {
        window.clearTimeout(resolveTimer);
        resolveTimer = 0;
      }
      drivePlayerEl.removeEventListener("loadedmetadata", onReady);
      drivePlayerEl.removeEventListener("error", onError);
      drivePlayerEl.removeEventListener("stalled", onError);
      drivePlayerEl.removeEventListener("abort", onError);
    };
    var onError = function () {
      if (resolved) return;
      onReady();
      fallbackToPreview("html5_error");
    };
    drivePlayerEl.addEventListener("loadedmetadata", onReady);
    drivePlayerEl.addEventListener("error", onError);
    drivePlayerEl.addEventListener("stalled", onError);
    drivePlayerEl.addEventListener("abort", onError);
    resolveTimer = window.setTimeout(function () {
      if (resolved) return;
      onReady();
      fallbackToPreview("html5_timeout");
    }, 5000);
    drivePlayerEl.src = playableUrl;
    drivePlayerEl.load();
    setStatus("Ładowanie odtwarzacza...");
    setDriveExternalLink(video || findVideoMetaBySource(source) || {});
  }

  function loadYouTube(videoId) {
    if (!videoId) return;
    if (playerType === "html5") destroyCurrentPlayer();
    setPlayerSurface("youtube");
    if (window.YT && window.YT.Player) {
      if (playerType === "youtube" && player && typeof player.loadVideoById === "function") {
        player.loadVideoById(videoId);
        playerReady = true;
        lastPlayerState = -1;
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
    if (!ytPlayerEl) return;
    resetPlayerState();
    playerType = "youtube";
    player = new window.YT.Player("yt-player", {
      videoId: videoId,
      playerVars: { rel: 0, modestbranding: 1, playsinline: 1 },
      events: {
        onReady: function () {
          playerReady = true;
          lastPlayerState = -1;
          setStatus("Gotowe");
        },
        onStateChange: function (event) {
          handlePlayerStateChange(Number(event && event.data));
        }
      }
    });
  }

  function loadVideoByProvider(video) {
    if (isLikelyDriveVideo(video || {})) {
      loadDriveVideo(video || {});
      return;
    }
    var ytVideoId = String(video && video.provider_video_id || video && video.youtube_id || source || "").trim();
    loadYouTube(ytVideoId);
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
      setStatus("Brak parametru source. Użyj np. /video/play.php?source=ID_FILMU");
      comments = [];
      renderComments();
      return;
    }
    if (!isSourceKey(source)) {
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
      canEditCurrentSource = !!(
        data &&
        data.access &&
        data.access.effective &&
        data.access.effective.can_edit_source === true
      );
      editMode = !!data.edit || canEditCurrentSource;
      updateGenerateTokenButtonVisibility();
      comments = Array.isArray(data.comments) ? data.comments : [];
      reviewPublishedSummary = data.review_summary_published || null;
      reviewPublishedSummaries = normalizePublishedReviewSummaries(data.review_summaries_published || reviewPublishedSummary);
      activePublishedReviewId = reviewPublishedSummaries.length ? Number(reviewPublishedSummaries[0].id || 0) : null;
      reviewDraftSummary = data.review_summary_draft || null;
      sortCommentsByTime();
      renderComments();
      renderReviewSummary(reviewPublishedSummaries);
      setStatus(editMode ? "Ładowanie odtwarzacza..." : "Tryb podglądu.");
      var mergedVideo = Object.assign({}, findVideoMetaBySource(source) || {}, data.video || {});
      loadVideoByProvider(mergedVideo);
      updateReviewMenuButtons();
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd ładowania.");
      comments = [];
      reviewPublishedSummary = null;
      reviewPublishedSummaries = [];
      activePublishedReviewId = null;
      reviewDraftSummary = null;
      renderComments();
      renderReviewSummary(null);
      if (addCommentBtn) addCommentBtn.hidden = true;
      hideForm(false);
      closeReviewModal(false);
      canEditCurrentSource = false;
      updateGenerateTokenButtonVisibility();
      updateReviewMenuButtons();
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
    if (isDriveSourceKey(source) && isDriveFileId(gdriveIdParam)) {
      rememberGdriveId(source, gdriveIdParam);
    }

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
    if (reviewModalCloseBtn) reviewModalCloseBtn.addEventListener("click", function () { closeReviewModal(true); });
    if (reviewModalOverlayEl) reviewModalOverlayEl.addEventListener("click", function () { closeReviewModal(true); });
    if (reviewSaveDraftBtn) reviewSaveDraftBtn.addEventListener("click", handleReviewSaveDraft);
    if (reviewPublishBtn) reviewPublishBtn.addEventListener("click", handleReviewPublish);
    if (reviewCancelBtn) reviewCancelBtn.addEventListener("click", function () { closeReviewModal(true); });
    document.addEventListener("keydown", handleAuthModalKeydown);
    document.addEventListener("keydown", handleAddModalKeydown);
    document.addEventListener("keydown", handleCommentModalKeydown);
    document.addEventListener("keydown", handleReviewModalKeydown);
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

