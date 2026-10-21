# launchroom dev fixture: a repository Dockerfile wins over Railpack (docs/04 §2).
# GREETING is a non-secret build variable passed as a build arg only because
# this Dockerfile declares it; BUILD_SECRET is mounted as a BuildKit secret
# for one step and never lands in a layer.
FROM busybox:1.37
ARG GREETING=hello
RUN --mount=type=secret,id=BUILD_SECRET,required=false \
    if [ -s /run/secrets/BUILD_SECRET ]; then echo "build secret is available"; fi
RUN mkdir -p /www && printf '%s from launchroom (dockerfile)\n' "$GREETING" > /www/index.html
EXPOSE 8080
CMD ["httpd", "-f", "-p", "8080", "-h", "/www"]
