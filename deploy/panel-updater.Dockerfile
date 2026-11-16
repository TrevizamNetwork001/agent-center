ARG BASE_IMAGE=agent-center-central:device-readonly-admin-web-20261113
FROM ${BASE_IMAGE}
USER root
WORKDIR /var/www/html
COPY app/app/Agent/Operations.php app/app/Agent/ReleaseAvailability.php app/app/Agent/Releases.php app/app/Agent/ReleaseTransitions.php app/Agent/
COPY app/app/Http/Controllers/Agent/ReleaseController.php app/app/Http/Controllers/Agent/UpdateController.php app/Http/Controllers/Agent/
COPY app/app/Models/AgentRelease.php app/Models/AgentRelease.php
# Preserve the deployed static capability contract and its device freeze.
RUN php -r '$p="app/Agent/Protocol.php"; $s=file_get_contents($p); $old="VERSIONS = [".chr(39)."0.6.5".chr(39).","; if (substr_count($s,$old)!==1) { exit(1); } if (file_put_contents($p,str_replace($old,"VERSIONS = [".chr(39)."0.6.6".chr(39).", ".chr(39)."0.6.5".chr(39).",",$s))===false) { exit(1); }'
COPY deploy/php/panel-release-upload.ini /usr/local/etc/php/conf.d/zz-panel-release-upload.ini
RUN php -l app/Agent/Protocol.php && php -l app/Agent/ReleaseTransitions.php && php -l app/Agent/Operations.php && php -l app/Http/Controllers/Agent/ReleaseController.php && php -l app/Http/Controllers/Agent/UpdateController.php
USER www-data
RUN php -r 'require "vendor/autoload.php"; if (!class_exists(App\Agent\ReleaseTransitions::class) || !in_array("0.6.6",App\Agent\Protocol::VERSIONS,true) || count(App\Agent\Protocol::CAPABILITIES)!==7 || ini_get("upload_max_filesize")!=="8M") { exit(1); }'
