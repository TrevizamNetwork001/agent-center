# Exact production RAG V1 base; only the deterministic conversation fix is added.
# Required image ID: sha256:fcfa83717242a742a5c51ae36999166c055e47aa0144f2aef18dc6874d98efda
FROM agent-center-central:rag-v1-final-20261111
COPY --chown=www-data:www-data app/app/Conversation/Engine.php /var/www/html/app/Conversation/Engine.php
COPY --chown=www-data:www-data app/app/Conversation/IntentDetector.php /var/www/html/app/Conversation/IntentDetector.php
