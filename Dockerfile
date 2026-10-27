FROM ghcr.io/nosial/ncc:latest

LABEL org.opencontainers.image.title="FederationJevGuard" \
      org.opencontainers.image.version="1.0.0" \
      ncc.package="net.nosial.federation_jevguard"

WORKDIR /app
COPY . /app

RUN ncc project install -y && ncc build --configuration=release
RUN ncc package install --package=target/release/net.nosial.federation_jevguard.ncc -y