# The start command names a binary that isn't in the image: exit code 127.
FROM golang:1.24-alpine AS build
WORKDIR /src
COPY go.mod main.go ./
RUN go build -o /out/failure-lab .

FROM alpine:3.22
COPY --from=build /out/failure-lab /usr/local/bin/failure-lab
CMD ["/usr/local/bin/failure-lab-missing"]
