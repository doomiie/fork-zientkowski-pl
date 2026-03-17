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

  document.addEventListener("DOMContentLoaded", initBulkSelect);
})();
