(function () {
  "use strict";

  var AUTH_API = "/backend/video_auth.php";
  var TOKENS_API = "/backend/video_tokens.php";
  var PAYMENT_API = "/backend/video_payment_p24.php";
  var VIDEO_API = "/backend/video.php";

  function byId(id) { return document.getElementById(id); }
  function pageName() {
    var raw = document.body && document.body.getAttribute("data-video-app-page");
    return String(raw || "").trim().toLowerCase();
  }
  function currentUserId() {
    var raw = document.body && document.body.getAttribute("data-vapp-user-id");
    var n = Number(raw || 0);
    return Number.isFinite(n) ? n : 0;
  }
  function currentUserRole() {
    var raw = document.body && document.body.getAttribute("data-vapp-user-role");
    return String(raw || "").trim().toLowerCase();
  }
  function currentUserRoles() {
    var raw = document.body && document.body.getAttribute("data-vapp-user-roles");
    return String(raw || "")
      .toLowerCase()
      .split(/[\s,;|]+/)
      .map(function (v) { return String(v || "").trim(); })
      .filter(function (v) { return !!v; });
  }
  function hasRole(role) {
    var needle = String(role || "").trim().toLowerCase();
    if (!needle) return false;
    var roles = currentUserRoles();
    if (roles.indexOf(needle) >= 0) return true;
    var main = currentUserRole();
    if (main === "trener" && needle === "editor") return true;
    if (main === "user" && needle === "viewer") return true;
    return main === needle;
  }
  function csrf() {
    var el = byId("vapp-csrf");
    return el ? String(el.value || "") : "";
  }

  async function api(url, opts) {
    var res = await fetch(url, Object.assign({ headers: { "Accept": "application/json" } }, opts || {}));
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok || !json.ok) throw new Error(json.message || "BĹ‚Ä…d API.");
    return json;
  }

  function setText(id, msg) {
    var el = byId(id);
    if (el) el.textContent = msg || "";
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  async function doLogout() {
    try {
      await api(AUTH_API + "?action=logout", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ csrf_token: csrf() })
      });
      window.location.replace("/video/");
    } catch (error) {
      alert(error instanceof Error ? error.message : "Nie udaĹ‚o siÄ™ wylogowaÄ‡.");
    }
  }

  function initLogout() {
    var btn = byId("vapp-logout-btn");
    if (!btn) return;
    btn.addEventListener("click", doLogout);
  }

  function initDrawerMenu() {
    var openBtn = byId("vapp-menu-toggle");
    var closeBtn = byId("vapp-menu-close");
    var overlay = byId("vapp-drawer-overlay");
    var drawer = byId("vapp-drawer");
    if (!openBtn || !closeBtn || !overlay || !drawer) return;

    function openDrawer() {
      overlay.hidden = false;
      drawer.setAttribute("aria-hidden", "false");
      openBtn.setAttribute("aria-expanded", "true");
      document.body.classList.add("vapp-drawer-open");
    }
    function closeDrawer() {
      overlay.hidden = true;
      drawer.setAttribute("aria-hidden", "true");
      openBtn.setAttribute("aria-expanded", "false");
      document.body.classList.remove("vapp-drawer-open");
    }

    openBtn.addEventListener("click", openDrawer);
    closeBtn.addEventListener("click", closeDrawer);
    overlay.addEventListener("click", closeDrawer);
    drawer.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", closeDrawer);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeDrawer();
    });
  }

  function initLoginForm() {
    var form = byId("vapp-login-form");
    if (!form) return;
    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      setText("vapp-login-status", "Logowanie...");
      var fd = new FormData(form);
      try {
        await api(AUTH_API + "?action=login", {
          method: "POST",
          headers: { "Content-Type": "application/json", "Accept": "application/json" },
          body: JSON.stringify({
            email: String(fd.get("email") || "").trim(),
            password: String(fd.get("password") || ""),
            csrf_token: csrf()
          })
        });
        setText("vapp-login-status", "Zalogowano.");
        window.location.href = "/video/index.php";
      } catch (error) {
        setText("vapp-login-status", error instanceof Error ? error.message : "BĹ‚Ä…d logowania.");
      }
    });
  }

  function initRegisterForm() {
    var form = byId("vapp-register-form");
    if (!form) return;
    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      setText("vapp-register-status", "Tworzenie konta...");
      var fd = new FormData(form);
      try {
        await api(AUTH_API + "?action=register", {
          method: "POST",
          headers: { "Content-Type": "application/json", "Accept": "application/json" },
          body: JSON.stringify({
            email: String(fd.get("email") || "").trim(),
            password: String(fd.get("password") || ""),
            csrf_token: csrf()
          })
        });
        setText("vapp-register-status", "Konto utworzone.");
        window.location.href = "/video/index.php";
      } catch (error) {
        setText("vapp-register-status", error instanceof Error ? error.message : "BĹ‚Ä…d rejestracji.");
      }
    });
  }

  function formatMoney(value, currency) {
    var n = Number(value || 0);
    return n.toFixed(2) + " " + String(currency || "PLN");
  }

  async function loadTokenSection() {
    var typesWrap = byId("vapp-token-types");
    var ordersBody = byId("vapp-token-orders");
    if (!typesWrap || !ordersBody) return;

    setText("vapp-tokens-status", "Ĺadowanie...");
    try {
      var balance = await api(TOKENS_API + "?action=my_balance");
      byId("vapp-token-balance").textContent =
        "Saldo: uploady " + balance.balance.remaining_upload_links +
        " | wybór trenera " + balance.balance.remaining_trainer_choices;

      var list = await api(TOKENS_API + "?action=list_types");
      typesWrap.innerHTML = "";
      (list.types || []).forEach(function (item) {
        var card = document.createElement("article");
        card.className = "vapp-pack";
        card.innerHTML =
          "<h3>" + escapeHtml(item.title) + "</h3>" +
          "<p>" + escapeHtml(item.description || "") + "</p>" +
          "<p>Uploady: <strong>" + Number(item.max_upload_links || 0) + "</strong></p>" +
          "<p>Wybr trenera: <strong>" + (Number(item.can_choose_trainer || 0) === 1 ? "tak" : "nie") + "</strong></p>" +
          "<p>Cena: <strong>" + escapeHtml(formatMoney(item.price_gross_pln, item.currency)) + "</strong></p>" +
          "<button class='vapp-btn' data-token-type='" + escapeHtml(String(item.id)) + "'>Kup teraz</button>";
        typesWrap.appendChild(card);
      });

      typesWrap.querySelectorAll("button[data-token-type]").forEach(function (btn) {
        btn.addEventListener("click", async function () {
          var tokenTypeId = Number(btn.getAttribute("data-token-type") || 0);
          if (!tokenTypeId) return;
          setText("vapp-tokens-status", "Tworzenie zamĂłwienia...");
          try {
            var orderRes = await api(TOKENS_API + "?action=create_order", {
              method: "POST",
              headers: { "Content-Type": "application/json", "Accept": "application/json" },
              body: JSON.stringify({
                csrf_token: csrf(),
                token_type_id: tokenTypeId
              })
            });
            setText("vapp-tokens-status", "Przekierowanie do pĹ‚atnoĹ›ci...");
            var checkout = await api(PAYMENT_API + "?action=checkout", {
              method: "POST",
              headers: { "Content-Type": "application/json", "Accept": "application/json" },
              body: JSON.stringify({
                csrf_token: csrf(),
                order_id: orderRes.order.id
              })
            });
            if (checkout.payment_url) window.location.href = checkout.payment_url;
            else setText("vapp-tokens-status", "Brak URL pĹ‚atnoĹ›ci.");
          } catch (error) {
            setText("vapp-tokens-status", error instanceof Error ? error.message : "BĹ‚Ä…d checkout.");
          }
        });
      });

      var ordersRes = await api(TOKENS_API + "?action=my_orders");
      ordersBody.innerHTML = "";
      (ordersRes.orders || []).forEach(function (order) {
        var tr = document.createElement("tr");
        tr.innerHTML =
          "<td>" + Number(order.id || 0) + "</td>" +
          "<td>" + escapeHtml(order.token_title || "-") + "</td>" +
          "<td>" + escapeHtml(order.status || "-") + "</td>" +
          "<td>" + escapeHtml(formatMoney(order.amount_gross_pln, order.currency)) + "</td>" +
          "<td>" + escapeHtml(order.created_at || "-") + "</td>";
        ordersBody.appendChild(tr);
      });
      setText("vapp-tokens-status", "");
    } catch (error) {
      setText("vapp-tokens-status", error instanceof Error ? error.message : "BĹ‚Ä…d Ĺ‚adowania.");
    }
  }

  async function refreshMyBalance() {
    try {
      var balance = await api(TOKENS_API + "?action=my_balance");
      setText("vapp-my-balance",
        "Saldo: uploady " + balance.balance.remaining_upload_links +
        " | wybór trenera " + balance.balance.remaining_trainer_choices);
    } catch (error) {
      setText("vapp-my-balance", error instanceof Error ? error.message : "BĹ‚Ä…d salda.");
    }
  }

  async function loadMyVideosSection() {
    var form = byId("vapp-my-video-form");
    if (!form) return;
    await refreshMyBalance();

    try {
      var trainersRes = await api(TOKENS_API + "?action=list_trainers");
      var select = byId("vapp-trainer-select");
      (trainersRes.trainers || []).forEach(function (t) {
        var opt = document.createElement("option");
        opt.value = String(t.id);
        opt.textContent = String(t.email);
        select.appendChild(opt);
      });
    } catch (error) {}

    if (form.dataset.boundSubmit !== "1") {
      form.addEventListener("submit", async function (event) {
        event.preventDefault();
        setText("vapp-my-video-status", "Dodawanie filmu...");
        var fd = new FormData(form);
        try {
          var payload = {
            csrf_token: csrf(),
            youtube_url: String(fd.get("youtube_url") || "").trim()
          };
          var trainer = String(fd.get("trainer_user_id") || "").trim();
          if (trainer) payload.trainer_user_id = Number(trainer);

          await api(VIDEO_API + "?action=add_user_video_link", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify(payload)
          });
          setText("vapp-my-video-status", "Film dodany.");
          form.reset();
          await loadMyVideosTable();
          await refreshMyBalance();
        } catch (error) {
          setText("vapp-my-video-status", error instanceof Error ? error.message : "BĹ‚Ä…d dodawania filmu.");
        }
      });
      form.dataset.boundSubmit = "1";
    }

    await loadMyVideosTable();
  }

  async function loadMyVideosTable() {
    var tbody = byId("vapp-my-videos-list");
    if (!tbody) return;
    try {
      var list = await api(VIDEO_API + "?action=list_videos");
      tbody.innerHTML = "";
      var userId = currentUserId();
      
      (list.videos || []).forEach(function (v) {
        var source = String(v.youtube_id || "");
        var title = String(v.tytul || source || "-");
        var trainerLabel = String(v.assigned_trainer_username || "").trim();
        if (!trainerLabel) trainerLabel = "-";
        var ownerId = Number(v.owner_user_id || 0);
        var canEditTitle = hasRole("admin") || (userId > 0 && ownerId === userId);
        var tr = document.createElement("tr");
        var titleClass = canEditTitle ? "vapp-inline-title vapp-inline-title--editable" : "vapp-inline-title";
        var titleHtml = canEditTitle
          ? ("<span class='vapp-inline-title__text'>" + escapeHtml(title) + "</span><span class='vapp-inline-title__icon' aria-hidden='true'>&#9998;</span>")
          : ("<span class='vapp-inline-title__text'>" + escapeHtml(title) + "</span>");

        tr.innerHTML =
          "<td><button type='button' class='" + titleClass + "' data-source='" + escapeHtml(source) + "' data-title='" + escapeHtml(title) + "' " + (canEditTitle ? "" : "disabled") + ">" + titleHtml + "</button></td>" +
          "<td>" + escapeHtml(trainerLabel) + "</td>" +
          "<td><a class='vapp-btn vapp-btn--ghost' href='/video/play.php?source=" + encodeURIComponent(source) + "' target='_blank' rel='noopener noreferrer'>Otwórz</a></td>";
        tbody.appendChild(tr);
      });

      tbody.querySelectorAll(".vapp-inline-title--editable").forEach(function (btn) {
        btn.addEventListener("click", function () { startInlineTitleEdit(btn); });
      });
    } catch (error) {
      tbody.innerHTML = "<tr><td colspan='3'>Brak danych.</td></tr>";
    }
  }

  function startInlineTitleEdit(buttonEl) {
    if (!buttonEl || buttonEl.dataset.editing === "1") return;
    buttonEl.dataset.editing = "1";

    var source = String(buttonEl.getAttribute("data-source") || "").trim();
    var initial = String(buttonEl.getAttribute("data-title") || buttonEl.textContent || "").trim();
    var td = buttonEl.closest("td");
    if (!td) {
      buttonEl.dataset.editing = "0";
      return;
    }

    var input = document.createElement("input");
    input.type = "text";
    input.className = "vapp-inline-title-input";
    input.maxLength = 160;
    input.value = initial;
    td.innerHTML = "";
    td.appendChild(input);
    input.focus();
    input.select();

    var finished = false;
    var finish = function (save) {
      if (finished) return;
      finished = true;
      var next = String(input.value || "").trim();

      var restore = function (titleText) {
        buttonEl.innerHTML = "<span class='vapp-inline-title__text'>" + escapeHtml(titleText) + "</span><span class='vapp-inline-title__icon' aria-hidden='true'>&#9998;</span>";
        buttonEl.setAttribute("data-title", titleText);
        buttonEl.dataset.editing = "0";
        td.innerHTML = "";
        td.appendChild(buttonEl);
      };

      if (!save) {
        restore(initial);
        return;
      }
      if (!next) {
        setText("vapp-my-video-status", "TytuĹ‚ nie moĹĽe byÄ‡ pusty.");
        restore(initial);
        return;
      }
      if (next === initial) {
        restore(initial);
        return;
      }

      setText("vapp-my-video-status", "Zapisywanie tytuĹ‚u...");
      td.innerHTML = "<span class='vapp-inline-title-saving'><span class='vapp-inline-title-saving__text'>" + escapeHtml(next) + "</span><span class='vapp-spinner' aria-hidden='true'></span></span>";

      api(VIDEO_API + "?action=update_user_video_title", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          csrf_token: csrf(),
          source: source,
          title: next
        })
      }).then(function (res) {
        var savedTitle = String(res.title || next);
        restore(savedTitle);
        setText("vapp-my-video-status", "TytuĹ‚ zapisany.");
      }).catch(function (error) {
        setText("vapp-my-video-status", error instanceof Error ? error.message : "Nie udaĹ‚o siÄ™ zapisaÄ‡ tytuĹ‚u.");
        restore(initial);
      });
    };

    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        finish(true);
      } else if (event.key === "Escape") {
        event.preventDefault();
        finish(false);
      }
    });
    input.addEventListener("blur", function () { finish(true); });
  }

  async function loadTrainerSection() {
    var tbody = byId("vapp-trainer-videos");
    if (!tbody) return;
    setText("vapp-trainer-status", "Ĺadowanie filmĂłw...");
    try {
      var list = await api(VIDEO_API + "?action=list_videos&edit=1");
      tbody.innerHTML = "";
      (list.videos || []).forEach(function (v) {
        var source = String(v.youtube_id || "");
        var tr = document.createElement("tr");
        tr.innerHTML =
          "<td>" + escapeHtml(String(v.tytul || source || "-")) + "</td>" +
          "<td><a class='vapp-btn' href='/video/play.php?source=" + encodeURIComponent(source) + "&edit=1'>Komentuj</a></td>";
        tbody.appendChild(tr);
      });
      setText("vapp-trainer-status", "");
    } catch (error) {
      setText("vapp-trainer-status", error instanceof Error ? error.message : "BĹ‚Ä…d Ĺ‚adowania.");
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    initDrawerMenu();
    initLogout();
    initLoginForm();
    initRegisterForm();

    var page = pageName();
    if (page === "tokens.php") loadTokenSection();
    if (page === "my-videos.php") loadMyVideosSection();
    if (page === "trener.php") loadTrainerSection();
  });
})();
