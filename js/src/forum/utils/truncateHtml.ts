/**
 * Safely truncate an HTML string without breaking opening/closing tags
 * Only characters in text nodes count towards the length
 * @param html
 * @param length
 */
export default function (html: string, length: number): string {
  const parser = new DOMParser().parseFromString(html, 'text/html');

  // Safe HTML truncation
  // Loop through every node and shorten text nodes once the length is reached
  // After reaching the limit, start deleting other children we come across
  const truncateNode = (node: Node) => {
    if (length === 0) {
      node.parentNode!.removeChild(node);
      return;
    }

    if (node.nodeType === Node.TEXT_NODE) {
      if (node.textContent!.length < length) {
        length -= node.textContent!.length;
      } else {
        node.textContent = node.textContent!.substring(0, length) + '...';
        length = 0;
      }
      return;
    }

    // Need to create copy of the node list otherwise the loop gets messed up when a node is deleted
    Array.from(node.childNodes).forEach(truncateNode);
  };

  truncateNode(parser.body);

  return parser.body.innerHTML;
}
