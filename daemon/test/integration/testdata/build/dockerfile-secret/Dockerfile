# syntax=docker/dockerfile:1
FROM busybox:1.37
ARG GREETING=default
# The secret is mounted for this step only: it is printed (the daemon must
# mask it in the logs) but never written into a layer.
RUN --mount=type=secret,id=BUILD_SECRET,env=BUILD_SECRET \
    echo "token is $BUILD_SECRET" && \
    echo "b64 $(printf %s "$BUILD_SECRET" | base64)" && \
    test -n "$BUILD_SECRET"
RUN echo "$GREETING" > /greeting
EXPOSE 8080
CMD ["httpd", "-f", "-p", "8080", "-h", "/"]
