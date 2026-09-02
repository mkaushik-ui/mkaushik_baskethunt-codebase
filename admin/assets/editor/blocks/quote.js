/**
 * Task A2-T06: Quote Block
 * Custom professional implementation for SOI Knowledge Center
 */
class QuoteBlock {
  static get toolbox() {
    return {
      title: 'Quote',
      icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>'
    };
  }

  static get isReadOnlySupported() {
    return true;
  }

  static get sanitize() {
    return {
      text: {
        b: true,
        i: true,
        u: true,
        a: { href: true },
        br: true
      },
      caption: {
        b: true,
        i: true,
        u: true,
        a: { href: true }
      }
    };
  }

  constructor({ data, api, readOnly }) {
    this.api = api;
    this.readOnly = readOnly;
    this.data = {
      text: data.text || '',
      caption: data.caption || ''
    };
    
    this.CSS = {
      baseBlock: this.api.styles.block,
      wrapper: 'kc-tool-quote',
      text: 'kc-quote-tool-text',
      caption: 'kc-quote-tool-caption'
    };
    
    this.nodes = {
      wrapper: null,
      text: null,
      caption: null
    };
  }

  validate(savedData) {
    if (!savedData.text || savedData.text.trim() === '') {
      return false;
    }
    return true;
  }

  render() {
    this.nodes.wrapper = document.createElement('blockquote');
    this.nodes.wrapper.classList.add(this.CSS.baseBlock, this.CSS.wrapper);
    
    // Text container
    this.nodes.text = document.createElement('div');
    this.nodes.text.classList.add(this.CSS.text);
    this.nodes.text.contentEditable = !this.readOnly;
    this.nodes.text.innerHTML = this.data.text;
    this.nodes.text.setAttribute('data-placeholder', 'Enter a quote...');
    
    // Caption container
    this.nodes.caption = document.createElement('div');
    this.nodes.caption.classList.add(this.CSS.caption);
    this.nodes.caption.contentEditable = !this.readOnly;
    this.nodes.caption.innerHTML = this.data.caption;
    this.nodes.caption.setAttribute('data-placeholder', 'Author or citation...');

    this.nodes.wrapper.appendChild(this.nodes.text);
    this.nodes.wrapper.appendChild(this.nodes.caption);

    if (!this.readOnly) {
      this.nodes.text.addEventListener('keydown', (e) => this._handleTextKeydown(e));
      this.nodes.caption.addEventListener('keydown', (e) => this._handleCaptionKeydown(e));
    }

    return this.nodes.wrapper;
  }

  _handleTextKeydown(e) {
    if (e.key === 'Enter') {
      if (!e.shiftKey) {
        e.preventDefault();
        // If Enter is pressed (without Shift), move to caption.
        // If we want Shift+Enter to be a line break, EditorJS natively supports it when preventDefault is skipped, 
        // but here we just prevent raw Enter and jump to caption.
        this.nodes.caption.focus();
      }
    } else if (e.key === 'ArrowDown') {
      // Jump to caption if we are at the end of the text
      const selection = window.getSelection();
      if (selection.rangeCount > 0) {
          // Simplistic jump
          this.nodes.caption.focus();
      }
    }
  }

  _handleCaptionKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      // Exit quote block, create new block below
      const currentIndex = this.api.blocks.getCurrentBlockIndex();
      this.api.blocks.insert();
      this.api.caret.setToBlock(currentIndex + 1, 'start');
    } else if (e.key === 'Backspace') {
      if (this.nodes.caption.textContent.trim() === '') {
        e.preventDefault();
        // Move back to text
        this.nodes.text.focus();
        // Set caret to end
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(this.nodes.text);
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
      }
    } else if (e.key === 'ArrowUp') {
       this.nodes.text.focus();
    }
  }

  save(blockContent) {
    const textNode = blockContent.querySelector(`.${this.CSS.text}`);
    const captionNode = blockContent.querySelector(`.${this.CSS.caption}`);
    return {
      text: textNode ? textNode.innerHTML : '',
      caption: captionNode ? captionNode.innerHTML : ''
    };
  }
}

window.QuoteBlock = QuoteBlock;
