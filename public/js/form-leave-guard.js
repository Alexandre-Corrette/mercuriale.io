/**
 * MERC-32 — Guard de navigation sur formulaires modifiés
 * Déclenche une confirmation si l'utilisateur quitte un formulaire
 * après avoir modifié au moins un champ.
 *
 * Usage : ajouter data-leave-guard sur le <form> concerné
 * <form data-leave-guard>...</form>
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form[data-leave-guard]');

        forms.forEach(function (form) {
            var isDirty = false;

            form.addEventListener('input', function () {
                isDirty = true;
            });
            form.addEventListener('change', function () {
                isDirty = true;
            });

            form.addEventListener('submit', function () {
                isDirty = false;
            });

            document.querySelectorAll('a.btn-back, .breadcrumb a').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    if (isDirty) {
                        var confirmed = window.confirm(
                            'Des modifications non enregistrées seront perdues. Continuer ?'
                        );
                        if (!confirmed) {
                            e.preventDefault();
                        }
                    }
                });
            });

            window.addEventListener('beforeunload', function (e) {
                if (isDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });
    });
}());
