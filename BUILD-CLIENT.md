# Isolierter RustDesk-Testclient

Dieser Workflow baut ausschließlich einen lokal signierten Entwicklungsclient. Er veröffentlicht
keine Releases oder Pakete, verwendet keine Secrets und verändert weder den installierten RustDesk-Client
noch den lokalen Referenzquellbaum.

## Auslösen und Artefakt

Nach dem Commit im eigenen Repository startet der Workflow bei Änderungen an seinen
eigenen Dateien. Auf einem Branch ohne diese Workflow-Datei kann `workflow_dispatch`
nicht angeboten werden; daher zuerst den Commit pushen und danach den manuellen Lauf
auf diesem Branch auswählen.

Das Artefakt `RustDeskLab-macos-arm64` enthält `RustDeskLab.app` als `.tar.gz`.
Entpacke es in einen separaten Ordner, zum Beispiel `~/Applications/RustDeskLab.app`,
und starte nur diese Kopie. Nicht nach `/Applications/RustDesk.app` kopieren und die
Produktiv-App nicht ersetzen.

Das Bundle besitzt nur eine lokale Ad-hoc-Signatur, keine Apple Developer ID und keine Notarisierung. Die dokumentierte Ausnahme für die Bibliotheksprüfung gilt ausschließlich für diese freigegebene Test-App. Sie ist keine Kundenfreigabe; Systemschutz und Gatekeeper bleiben unverändert.

## Reproduzierbarer Quellenstand

Der Workflow checkt den öffentlichen RustDesk-Commit
`23e5bb471a040d62f0a6ad52591fb30784265b73` mit Submodulen aus und verifiziert:

- `libs/hbb_common` auf `fae47e024dd83cef6aaecde0aa7d11731df11649`;
- SHA-256 der acht geprüften Upstream-Dateien vor jeder Änderung;
- den SHA-256 von `src/client.rs` nach `client-1.5.1-strict.patch`.

Bei einer abweichenden Upstream-Datei bricht `prepare-test-client.py` ab, statt einen
Patch unsicher oder teilweise anzuwenden.

## Isolierungsdiff

Zusätzlich zu `experimental/secure-tcp-v1/client-1.5.1-strict.patch` nimmt das
Vorbereitungsskript in der nur für den Runner ausgecheckten Clientkopie diese Änderungen
vor:

- `APP_NAME`: `RustDesk` → `RustDeskLab`. Das trennt Konfiguration, Preferences und
  IPC-Namen von der installierten App.
- macOS `PRODUCT_NAME`: `RustDesk` → `RustDeskLab`. Das gebaute Bundle heißt daher
  `RustDeskLab.app`.
- macOS `PRODUCT_BUNDLE_IDENTIFIER`: `com.carriez.flutterHbb` beziehungsweise
  `com.carriez.rustdesk` → `de.sandav.rustdesklab`.

- macOS-URL-Schema: `rustdesklab://` statt `rustdesk://`, damit der Testbuild
  nicht den URL-Namen der produktiven App beansprucht.
- `client-1.5.1-address-book-notes.patch` ergänzt ausschließlich die alte
  Adressbuchschnittstelle um Notizänderung und optionale Notizserialisierung.

Die exakten ursprünglichen Zeichenfolgen und ihre Anzahl werden vor dem Ersetzen
geprüft. Der Workflow verwendet den offiziellen RustDesk-1.5.1-Flutterpfad: separate
Bridge-Generierung mit Flutter 3.22.3 und `flutter_rust_bridge_codegen` 1.80.1,
anschließend macOS-arm64-Build mit Rust 1.81, Flutter 3.24.5 und gepinntem vcpkg.

## Linux-x64-Testbundle

`.github/workflows/build-test-linux.yml` ist ein getrennt auslösbarer, nativer
Ubuntu-22.04-x64-Build. Er erzeugt kein Deb, RPM oder Installationsskript, sondern
nur `RustDeskLab-linux-x64-bundle.tar.gz` aus
`flutter/build/linux/x64/release/bundle`.

Auf dem Testsystem das Archiv in einen beschreibbaren, separaten Ordner entpacken
und `bundle/rustdesk` von dort starten. Nicht als Root installieren und kein
Paketmanagement verwenden. Der vorbereitende Strict-Patch und `APP_NAME=RustDeskLab`
werden auch für diesen Build geprüft und angewendet; die zusätzlichen macOS-Metadaten
in derselben isolierten Quellkopie haben auf das Linux-Bundle keine Wirkung.

## Local macOS lab packaging check (2026-11-09)

The first successful arm64 build (run 42703740829) copied `Contents/MacOS/service` after Xcode had sealed the app. Its original archive therefore requires resealing before use. The packaging workflow now reseals the app and embedded binaries with ad-hoc signatures, adds `com.apple.security.cs.disable-library-validation` only for this explicitly approved local development build (there is no Apple Team ID), and runs `codesign --verify --deep --strict` before archiving. This is still an unnotarized local development build with no Apple Developer ID, unsuitable as a customer release; it does not bypass Gatekeeper or grant screen/input permissions.

The downloaded first build was extracted to a task-owned directory outside a file-provider-managed Documents folder, resealed with its existing identifier and runtime flags preserved and with the explicitly approved development-only library-validation exception, and passed the same deep/strict verification. No executable code was changed by this packaging correction. Archive digest and original build digest are recorded separately.
