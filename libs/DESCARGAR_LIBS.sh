#!/bin/bash
# Ejecuta este script UNA VEZ para descargar las librerías necesarias
echo "Descargando librerías..."
curl -L "https://cdn.jsdelivr.net/npm/highlight.js@11/lib/highlight.min.js" -o highlight.min.js
curl -L "https://cdn.jsdelivr.net/npm/highlight.js@11/styles/github-dark.min.css" -o github-dark.min.css
curl -L "https://cdn.jsdelivr.net/npm/marked@9/marked.min.js" -o marked.min.js
echo "¡Listo! Las librerías están descargadas."
