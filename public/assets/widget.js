/*
 * GU-AIA widget enhancement. requirements.md Section 10.
 *
 * PROGRESSIVE ENHANCEMENT, NOT A REQUIREMENT. The form works without any of
 * this: it posts to the same endpoint and the server returns a cited answer as
 * HTML (INV-9). Everything below only avoids a full page reload and manages
 * focus. If this file fails to load, or fails to parse, the widget still works.
 *
 * That is why it attaches to an already-functional form rather than building
 * one, and why it calls preventDefault only after confirming it can handle the
 * submission itself.
 */
(function () {
  'use strict';

  var form = document.querySelector('.gu-aia-form');
  var section = document.querySelector('.gu-aia');
  if (!form || !section || !window.fetch) return;

  function replaceAnswer(html) {
    var old = section.querySelector('.gu-aia-answer');
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var fresh = holder.firstElementChild;
    if (!fresh) return;

    if (old) old.replaceWith(fresh);
    else section.appendChild(fresh);

    /* WCAG 2.1 AA, Section 10: "focus managed on new answers". aria-live
       announces it; moving focus means a keyboard user lands on the answer
       instead of being left at the button they just pressed. */
    fresh.focus();
  }

  form.addEventListener('submit', function (event) {
    var field = form.querySelector('input[name="question"]');
    if (!field || !field.value.trim()) return;

    event.preventDefault();
    section.classList.add('gu-aia-busy');

    fetch(form.action, {
      method: 'POST',
      headers: { 'Accept': 'text/html+fragment' },
      body: new FormData(form),
      credentials: 'same-origin'
    })
      .then(function (response) {
        if (!response.ok && response.status !== 429) throw new Error('bad status');
        return response.text();
      })
      .then(replaceAnswer)
      .catch(function () {
        /* Any failure hands the submission back to the browser, which does the
           plain form post the page was built around. Never a dead end. */
        form.submit();
      })
      .then(function () {
        section.classList.remove('gu-aia-busy');
      });
  });
})();
