/** Fixtures are imported as text so a test never has to find them on disk. */
declare module '*.html?raw' {
  const content: string;
  export default content;
}
