Trix 2.1.19 — https://github.com/basecamp/trix — MIT (see LICENSE).

Vendored rather than loaded from a CDN: the app is deployed by FTP onto shared
hosting and has no build step, and a club's site should not stop working
because a third-party CDN is unreachable.

Files are the unmodified UMD build from the npm package:
  trix.min.js  <- dist/trix.umd.min.js   (UMD, so no module loader is needed)
  trix.css     <- dist/trix.css

To update: npm pack trix, then copy those two files across and check the
version above. Nothing else in the package is used.
