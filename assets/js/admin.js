/* =============================================================
   Seliweb — Admin JS
   ============================================================= */
(function ($) {
    'use strict';

    /* ---------------------------------------------------------
       Formulaire annonce : compteur de caractères sur le texte
       --------------------------------------------------------- */
    var $texte = $('#texte');
    if ($texte.length) {
        var $counter = $('<p class="description seliweb-counter"></p>');
        $texte.after($counter);

        function updateCounter() {
            var len  = $texte.val().length;
            var max  = parseInt($texte.attr('maxlength'), 10) || 1000;
            var left = max - len;
            $counter.text(left + ' / ' + max + ' caractère(s) restant(s)');
            $counter.css('color', left < 100 ? '#b32d2e' : '#555');
        }

        $texte.on('input', updateCounter);
        updateCounter();
    }

    /* ---------------------------------------------------------
       Formulaire annonce : confirmation avant suppression photo
       --------------------------------------------------------- */
    $(document).on('click', '.seliweb-delete-photo', function (e) {
        if (!confirm('Supprimer cette photo ?')) {
            e.preventDefault();
        }
    });

    /* ---------------------------------------------------------
       Membres : feedback visuel lors du changement de groupe
       --------------------------------------------------------- */
    $(document).on('change', 'select[name="groupe_id"]', function () {
        var $row = $(this).closest('tr');
        $row.css('background', '#fff8e1');
        setTimeout(function () {
            $row.css('background', '');
        }, 800);
    });

}(jQuery));
