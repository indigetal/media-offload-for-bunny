(function () {
  const initConditionalFields = (config) => {
    const toggle = document.querySelector(config.toggleSelector);
    const section = document.querySelector(config.sectionSelector);

    if (!toggle || !section) {
      return;
    }

    const dependentRows = Array.from(section.querySelectorAll(config.rowSelector));
    const dependentControls = Array.from(section.querySelectorAll(config.controlSelector));

    const syncFields = (enabled) => {
      toggle.checked = enabled;
      toggle.setAttribute("aria-expanded", enabled ? "true" : "false");

      section.classList.toggle(config.enabledClass, enabled);
      section.classList.toggle(config.disabledClass, !enabled);

      dependentRows.forEach((row) => {
        row.hidden = !enabled;
        row.setAttribute("aria-hidden", enabled ? "false" : "true");
      });

      dependentControls.forEach((control) => {
        control.disabled = !enabled;
      });
    };

    toggle.addEventListener("change", () => syncFields(toggle.checked));
    const initialEnabled =
      section.classList.contains(config.enabledClass) || toggle.checked;
    syncFields(initialEnabled);
  };

  initConditionalFields({
    toggleSelector: "#indigetal_offload_storage_enabled",
    sectionSelector: '[data-bmo-storage-section="1"]',
    rowSelector: '[data-bmo-storage-dependent-field="1"]',
    controlSelector: '[data-bmo-storage-dependent-control="1"]',
    enabledClass: "bmo-section--storage-enabled",
    disabledClass: "bmo-section--storage-off",
  });

  initConditionalFields({
    toggleSelector: "#indigetal_offload_stream_enabled",
    sectionSelector: '[data-bmo-stream-section="1"]',
    rowSelector: '[data-bmo-stream-dependent-field="1"]',
    controlSelector: '[data-bmo-stream-dependent-control="1"]',
    enabledClass: "bmo-section--stream-enabled",
    disabledClass: "bmo-section--stream-off",
  });

  /**
   * Disabled fields are dropped from the POST body. When the master Stream or
   * Storage toggle is on, re-enable dependents for submit only so credentials
   * typed while controls were briefly disabled still reach options.php.
   */
  const settingsForm = document.querySelector(
    ".bmo-settings-panel form[action*='options.php']"
  );
  if (settingsForm) {
    settingsForm.addEventListener("submit", () => {
      const streamToggle = document.getElementById("indigetal_offload_stream_enabled");
      if (streamToggle && streamToggle.checked) {
        document
          .querySelectorAll('[data-bmo-stream-dependent-control="1"]')
          .forEach((el) => {
            el.disabled = false;
          });
      }
      const storageToggle = document.getElementById("indigetal_offload_storage_enabled");
      if (storageToggle && storageToggle.checked) {
        document
          .querySelectorAll('[data-bmo-storage-dependent-control="1"]')
          .forEach((el) => {
            el.disabled = false;
          });
      }
    });
  }
})();
