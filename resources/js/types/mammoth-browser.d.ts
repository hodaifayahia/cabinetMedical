// mammoth's prebuilt browser bundle (UMD, no Node built-ins). The import is
// typed at its call site in resources/js/lib/docxImport.ts.
declare module 'mammoth/mammoth.browser.js' {
    const mammoth: unknown;
    export default mammoth;
}
