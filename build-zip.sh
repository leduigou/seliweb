#!/usr/bin/env bash
# Construit un zip propre du plugin pour installation manuelle sur un nouveau site.
#
# Utilise `git archive` : seuls les fichiers suivis par git sont inclus,
# donc .git/ et .claude/ (jamais trackés) ne s'y retrouvent jamais, même
# par erreur. Ne remplace pas les releases GitHub (utilisées par l'updater
# automatique) : sert pour l'installation initiale sur un site qui n'a pas
# encore le plugin.
set -euo pipefail

cd "$(dirname "$0")"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Attention : modifications non commitées présentes." >&2
    echo "Le zip sera construit à partir du dernier commit (HEAD), pas de l'état actuel du dossier." >&2
    git status --porcelain >&2
    read -rp "Continuer quand même ? [o/N] " reponse
    [[ "$reponse" =~ ^[oO]$ ]] || { echo "Annulé."; exit 1; }
fi

version=$(grep -m1 "SELIWEB_VERSION" seliweb.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
out_dir="dist"
out_file="${out_dir}/seliweb-${version}.zip"

mkdir -p "$out_dir"
git archive --format=zip --prefix=seliweb/ -o "$out_file" HEAD

echo "Zip généré : $out_file ($(du -h "$out_file" | cut -f1))"
