(function () {
  "use strict";

  function getBoxes() {
    return Array.prototype.slice.call(document.querySelectorAll(".bulk-video-checkbox"));
  }

  function setAll(checked) {
    getBoxes().forEach(function (box) {
      box.checked = !!checked;
    });
    syncMaster();
  }

  function syncMaster() {
    var master = document.getElementById("bulk-select-master");
    if (!master) return;
    var boxes = getBoxes();
    if (!boxes.length) {
      master.checked = false;
      master.indeterminate = false;
      return;
    }
    var checked = boxes.filter(function (box) { return box.checked; }).length;
    master.checked = checked === boxes.length;
    master.indeterminate = checked > 0 && checked < boxes.length;
  }

  function initBulkSelect() {
    var allBtn = document.getElementById("bulk-select-all-btn");
    var noneBtn = document.getElementById("bulk-select-none-btn");
    var master = document.getElementById("bulk-select-master");
    if (!allBtn || !noneBtn || !master) return;

    allBtn.addEventListener("click", function () { setAll(true); });
    noneBtn.addEventListener("click", function () { setAll(false); });
    master.addEventListener("change", function () { setAll(master.checked); });

    getBoxes().forEach(function (box) {
      box.addEventListener("change", syncMaster);
    });
    syncMaster();
  }

  function initTitleInlineEdit() {
    var forms = Array.prototype.slice.call(document.querySelectorAll("[data-video-title-form]"));
    if (!forms.length) return;

    forms.forEach(function (form) {
      var wrap = form.querySelector("[data-video-title-edit]");
      var trigger = form.querySelector("[data-video-title-trigger]");
      var input = form.querySelector("[data-video-title-input]");
      if (!wrap || !trigger || !input) return;

      var original = String(input.value || "");
      var wasChanged = false;

      function startEditing() {
        wrap.classList.add("is-editing");
        window.requestAnimationFrame(function () {
          input.focus();
          input.select();
        });
      }

      function stopEditing(save) {
        wrap.classList.remove("is-editing");
        if (!save) {
          input.value = original;
          return;
        }
        var next = String(input.value || "").trim();
        if (!next) {
          input.value = original;
          return;
        }
        if (next === original && !wasChanged) {
          return;
        }
        original = next;
        input.value = next;
        wasChanged = false;
        if (typeof form.requestSubmit === "function") {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }

      trigger.addEventListener("click", function () {
        startEditing();
      });

      input.addEventListener("blur", function () {
        stopEditing(true);
      });

      input.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          event.preventDefault();
          stopEditing(false);
        } else if (event.key === "Enter") {
          event.preventDefault();
          stopEditing(true);
        }
      });

      input.addEventListener("input", function () {
        wasChanged = true;
      });
    });
  }

  function initSearchableSelects() {
    var wrappers = Array.prototype.slice.call(document.querySelectorAll("[data-searchable-select-wrapper]"));
    if (!wrappers.length) return;

    wrappers.forEach(function (wrapper) {
      var input = wrapper.querySelector("[data-searchable-select-input]");
      var select = wrapper.querySelector("[data-searchable-select]");
      var meta = wrapper.querySelector("[data-searchable-select-meta]");
      if (!input || !select) return;

      var originalOptions = Array.prototype.slice.call(select.options).map(function (option, index) {
        return {
          value: String(option.value || ""),
          text: String(option.text || ""),
          disabled: !!option.disabled,
          selected: !!option.selected,
          isPlaceholder: index === 0 && String(option.value || "") === ""
        };
      });

      function setMeta(visibleCount, totalCount, hasQuery) {
        if (!meta) return;
        if (!hasQuery) {
          meta.textContent = totalCount > 0 ? ("Dostepne: " + totalCount) : "";
          return;
        }
        meta.textContent = "Pasuje: " + visibleCount + " z " + totalCount;
      }

      function rebuild() {
        var query = String(input.value || "").trim().toLowerCase();
        var selectedValue = String(select.value || "");
        var placeholder = originalOptions.filter(function (item) { return item.isPlaceholder; })[0] || null;
        var searchable = originalOptions.filter(function (item) { return !item.isPlaceholder; });
        var matches = searchable.filter(function (item) {
          return query === "" || item.text.toLowerCase().indexOf(query) !== -1;
        });

        select.innerHTML = "";

        if (placeholder) {
          var placeholderOption = new Option(placeholder.text, placeholder.value, false, selectedValue === placeholder.value);
          placeholderOption.disabled = false;
          select.add(placeholderOption);
        }

        matches.forEach(function (item) {
          var option = new Option(item.text, item.value, false, selectedValue === item.value);
          option.disabled = item.disabled;
          select.add(option);
        });

        if (matches.length === 0) {
          var emptyLabel = select.getAttribute("data-empty-label") || "Brak wynikow";
          var emptyOption = new Option(emptyLabel, "", false, false);
          emptyOption.disabled = true;
          select.add(emptyOption);
          if (selectedValue !== "") {
            select.value = placeholder ? placeholder.value : "";
          }
        } else if (selectedValue !== "" && matches.every(function (item) { return item.value !== selectedValue; })) {
          select.value = placeholder ? placeholder.value : matches[0].value;
        }

        setMeta(matches.length, searchable.length, query !== "");
      }

      input.addEventListener("input", rebuild);
      rebuild();
    });
  }

  function boot() {
    initBulkSelect();
    initTitleInlineEdit();
    initSearchableSelects();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
