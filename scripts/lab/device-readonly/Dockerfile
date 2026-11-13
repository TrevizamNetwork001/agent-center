# Disposable protocol tooling; never part of the Central/Agent release images.
FROM agent-center-tooling:foundation
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3-paramiko && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
CMD ["python3", "/protocol/server.py"]
