(function () {
  'use strict';

  function initCreditToggle(scope) {
    var wrappers = (scope || document).querySelectorAll('[data-acme-credit-toggle]');

    wrappers.forEach(function (wrapper) {
      if (wrapper.dataset.acmeToggleReady === '1') {
        return;
      }

      wrapper.dataset.acmeToggleReady = '1';

      var sectionCard = wrapper.closest('.acme-profile-card');
      if (!sectionCard) {
        return;
      }

      var buttons = wrapper.querySelectorAll('[data-acme-credit-target]');
      var panels = sectionCard.querySelectorAll('[data-acme-credit-panel]');

      if (!buttons.length || !panels.length) {
        return;
      }

      function activate(target) {
        buttons.forEach(function (button) {
          var isActive = button.getAttribute('data-acme-credit-target') === target;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(function (panel) {
          var isActive = panel.getAttribute('data-acme-credit-panel') === target;
          panel.classList.toggle('is-active', isActive);

          if (isActive) {
            panel.removeAttribute('hidden');
          } else {
            panel.setAttribute('hidden', 'hidden');
          }
        });
      }

      buttons.forEach(function (button) {
        button.addEventListener('click', function () {
          var target = button.getAttribute('data-acme-credit-target');
          activate(target);
        });
      });

      var defaultButton = wrapper.querySelector('[data-acme-credit-target].is-active');
      var defaultTarget = defaultButton
        ? defaultButton.getAttribute('data-acme-credit-target')
        : buttons[0].getAttribute('data-acme-credit-target');

      activate(defaultTarget);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initCreditToggle(document);
  });

  window.acmeShortcodeLayout = window.acmeShortcodeLayout || {
    initCreditToggle: initCreditToggle
  };
})();