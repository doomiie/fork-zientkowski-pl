(function () {
  "use strict";

  var API_URL = "/backend/video.php";
  var params = new URLSearchParams(window.location.search);
  var source = (params.get("source") || "").trim();
  var editMode = params.get("edit") === "1";

  var titleEl = document.getElementById("video-title");
  var statusEl = document.getElementById("video-status");
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
  var variantInput = document.getElementById("comment-variant");
  var authorInput = document.getElementById("comment-author");
  var commentIdInput = document.getElementById("comment-id");

  var comments = [];
  var player = null;
  var playerReady = false;
  var activeCommentId = null;
  var editingCommentId = null;

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
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

  function highlightComment(commentId) {
    activeCommentId = commentId;
    var buttons = commentsListEl.querySelectorAll(".video-comments__item");
    buttons.forEach(function (btn) {
      btn.classList.toggle("is-active", Number(btn.dataset.id) === Number(commentId));
    });
  }

  function seekAndPlay(seconds, commentId) {
    if (!playerReady || !player) return;
    var sec = Math.max(0, Number(seconds) || 0);
    player.seekTo(sec, true);
    player.playVideo();
    highlightComment(commentId);
  }

  function renderComments() {
    commentsListEl.innerHTML = "";
    if (!comments.length) {
      commentsEmptyEl.hidden = false;
      return;
    }
    commentsEmptyEl.hidden = true;

    var fragment = document.createDocumentFragment();
    comments.forEach(function (comment) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "video-comments__item";
      btn.dataset.id = String(comment.id);
      btn.dataset.time = String(comment.czas_sekundy || 0);
      btn.setAttribute("aria-label", "Przejdź do " + (comment.czas_tekst || formatTime(comment.czas_sekundy || 0)));
      btn.innerHTML =
        '<span class="video-comments__time">' + escapeHtml(comment.czas_tekst || formatTime(comment.czas_sekundy || 0)) + "</span>" +
        '<span class="video-comments__title">' + escapeHtml(comment.tytul || "Komentarz") + "</span>" +
        '<span class="video-comments__content">' + escapeHtml(comment.tresc || "") + "</span>";

      if (editMode) {
        var actions = document.createElement("div");
        actions.className = "video-comments__actions";

        var editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "video-comments__action";
        editBtn.textContent = "Edytuj";
        editBtn.setAttribute("aria-label", "Edytuj komentarz");
        editBtn.addEventListener("click", function (event) {
          event.stopPropagation();
          startEditComment(comment);
        });

        var deleteBtn = document.createElement("button");
        deleteBtn.type = "button";
        deleteBtn.className = "video-comments__action video-comments__action--danger";
        deleteBtn.textContent = "Usuń";
        deleteBtn.setAttribute("aria-label", "Usuń komentarz");
        deleteBtn.addEventListener("click", function (event) {
          event.stopPropagation();
          deleteComment(comment.id);
        });

        actions.appendChild(editBtn);
        actions.appendChild(deleteBtn);
        btn.appendChild(actions);
      }

      btn.addEventListener("click", function () {
        seekAndPlay(comment.czas_sekundy || 0, comment.id);
      });
      fragment.appendChild(btn);
    });
    commentsListEl.appendChild(fragment);
  }

  function showFormAtCurrentTime() {
    if (!playerReady || !player) return;
    var seconds = Math.max(0, Math.floor(player.getCurrentTime() || 0));
    timeInput.value = String(seconds);
    timeTextInput.value = formatTime(seconds);
    formStatusEl.textContent = "";
    formSection.hidden = false;
    editingCommentId = null;
    commentIdInput.value = "";
    formEl.querySelector('button[type="submit"]').textContent = "Zapisz komentarz";
    titleInput.focus();
  }

  function hideForm() {
    formSection.hidden = true;
    formEl.reset();
    variantInput.value = "ogolny";
    formStatusEl.textContent = "";
    editingCommentId = null;
    commentIdInput.value = "";
    formEl.querySelector('button[type="submit"]').textContent = "Zapisz komentarz";
  }

  function startEditComment(comment) {
    if (!editMode) return;
    formSection.hidden = false;
    editingCommentId = Number(comment.id);
    commentIdInput.value = String(comment.id);
    timeInput.value = String(comment.czas_sekundy || 0);
    timeTextInput.value = String(comment.czas_tekst || formatTime(comment.czas_sekundy || 0));
    titleInput.value = String(comment.tytul || "");
    contentInput.value = String(comment.tresc || "");
    variantInput.value = String(comment.wariant || "ogolny");
    authorInput.value = String(comment.autor || "");
    formStatusEl.textContent = "";
    formEl.querySelector('button[type="submit"]').textContent = "Zapisz zmiany";
    titleInput.focus();
  }

  async function deleteComment(commentId) {
    if (!editMode) return;
    var ok = window.confirm("Usunąć ten komentarz?");
    if (!ok) return;

    try {
      var response = await fetch(API_URL + "?action=delete_comment", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify({
          source: source,
          comment_id: Number(commentId)
        })
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Nie udało się usunąć komentarza.");
      }
      comments = comments.filter(function (c) { return Number(c.id) !== Number(commentId); });
      renderComments();
      if (Number(editingCommentId) === Number(commentId)) {
        hideForm();
      }
      setStatus("Komentarz usunięty.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd usuwania komentarza.");
    }
  }

  function loadYouTube(videoId) {
    window.onYouTubeIframeAPIReady = function () {
      player = new window.YT.Player("yt-player", {
        videoId: videoId,
        playerVars: {
          rel: 0,
          modestbranding: 1,
          playsinline: 1
        },
        events: {
          onReady: function () {
            playerReady = true;
            setStatus("Gotowe");
          },
          onStateChange: function (event) {
            // 2 = paused
            if (editMode && event.data === 2) {
              addCommentBtn.hidden = false;
            } else if (event.data === 1) {
              addCommentBtn.hidden = true;
            }
          }
        }
      });
    };

    var tag = document.createElement("script");
    tag.src = "https://www.youtube.com/iframe_api";
    tag.async = true;
    document.head.appendChild(tag);
  }

  async function fetchData() {
    var url = API_URL + "?action=load&source=" + encodeURIComponent(source) + (editMode ? "&edit=1" : "");
    var response = await fetch(url, { headers: { "Accept": "application/json" } });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) {
      throw new Error(data.message || "Nie udało się pobrać danych filmu.");
    }
    return data;
  }

  async function submitComment(event) {
    event.preventDefault();
    formStatusEl.textContent = "Zapisywanie...";

    var actionName = editingCommentId ? "update_comment" : "add_comment";
    var payload = {
      action: actionName,
      source: source,
      comment_id: editingCommentId ? Number(editingCommentId) : undefined,
      czas_sekundy: String(Math.max(0, Number(timeInput.value || "0") || 0)),
      czas_tekst: (timeTextInput.value || "").trim(),
      tytul: (titleInput.value || "").trim(),
      tresc: (contentInput.value || "").trim(),
      wariant: (variantInput.value || "").trim(),
      autor: (authorInput.value || "").trim()
    };

    try {
      var response = await fetch(API_URL + "?action=" + encodeURIComponent(actionName), {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify(payload)
      });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok || !data.comment) {
        throw new Error(data.message || "Nie udało się zapisać komentarza.");
      }

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
      addCommentBtn.hidden = false;
    } catch (error) {
      formStatusEl.textContent = error instanceof Error ? error.message : "Błąd zapisu.";
    }
  }

  async function init() {
    if (!source) {
      setStatus("Brak parametru source. Użyj np. video.html?source=YOUTUBE_ID");
      return;
    }
    if (!/^[A-Za-z0-9_-]{6,20}$/.test(source)) {
      setStatus("Niepoprawny parametr source.");
      return;
    }

    try {
      var data = await fetchData();
      titleEl.textContent = (data.video && data.video.tytul) ? data.video.tytul : "Wideo";
      comments = Array.isArray(data.comments) ? data.comments : [];
      renderComments();
      loadYouTube(data.video.youtube_id || source);
      setStatus("Ładowanie odtwarzacza...");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Błąd ładowania.");
    }
  }

  addCommentBtn.addEventListener("click", showFormAtCurrentTime);
  cancelBtn.addEventListener("click", hideForm);
  formEl.addEventListener("submit", submitComment);

  if (!editMode) {
    addCommentBtn.hidden = true;
  }

  init();
})();
