# syntax=docker/dockerfile:1
# launchroom integration fixture for inventory tasks (docs/22 §3): an app
# with one installed production package (ms), one installed development
# package (is-number), and two production packages that are only bundled:
# lodash referenced from the server output, zod only from browser chunks.
FROM busybox:1.37
COPY . /app/
WORKDIR /app
RUN mkdir -p .next/server/app .next/static/chunks && \
    echo 'module.exports = require("../../node_modules/lodash/lodash.js")' > .next/server/app/page.js && \
    echo '"[project]/node_modules/zod/lib/index.js [client]"' > .next/static/chunks/main.js
EXPOSE 8080
CMD ["httpd", "-f", "-p", "8080", "-h", "/app"]
