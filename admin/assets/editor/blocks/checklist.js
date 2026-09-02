/**
 * Task A2-T05: Checklist Block
 * Custom professional implementation for SOI Knowledge Center
 */
class ChecklistBlock {
  static get toolbox() {
    return {
      title: 'Checklist',
      icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>'
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
        s: true,
        a: { href: true },
        code: true
      }
    };
  }

  constructor({ data, api, readOnly }) {
    this.api = api;
    this.readOnly = readOnly;
    this.data = {
      items: data.items || []
    };
    
    // CSS classes matching kc-editor.css tokens (similar to list but customized)
    this.CSS = {
      baseBlock: this.api.styles.block,
      wrapper: 'kc-tool-checklist',
      item: 'kc-checklist-tool-item',
      checkbox: 'kc-checklist-tool-checkbox',
      text: 'kc-checklist-tool-text',
      itemChecked: 'kc-checklist-tool-item-checked'
    };
    
    this.nodes = {
      wrapper: null
    };
  }

  validate(savedData) {
    if (!savedData.items || savedData.items.length === 0) {
      return false;
    }
    return true;
  }

  render() {
    this.nodes.wrapper = document.createElement('div');
    this.nodes.wrapper.classList.add(this.CSS.baseBlock, this.CSS.wrapper);
    
    if (this.data.items.length === 0) {
      this.nodes.wrapper.appendChild(this._createItem());
    } else {
      this.data.items.forEach(itemData => {
        this.nodes.wrapper.appendChild(this._createItem(itemData.text, itemData.checked));
      });
    }
    
    if (!this.readOnly) {
      this.nodes.wrapper.addEventListener('keydown', (e) => {
        const item = e.target.closest(`.${this.CSS.item}`);
        if (!item) return;

        if (e.key === 'Enter') {
          this._handleEnter(e, item);
        } else if (e.key === 'Backspace') {
          this._handleBackspace(e, item);
        }
      });
    }

    return this.nodes.wrapper;
  }

  _createItem(text = '', checked = false) {
    const item = document.createElement('div');
    item.classList.add(this.CSS.item);
    if (checked) {
      item.classList.add(this.CSS.itemChecked);
    }
    
    // Checkbox UI element
    const checkbox = document.createElement('div');
    checkbox.classList.add(this.CSS.checkbox);
    // Render icon based on state. 
    checkbox.innerHTML = this._getCheckboxIcon(checked);
    
    if (!this.readOnly) {
      checkbox.addEventListener('click', () => {
        item.classList.toggle(this.CSS.itemChecked);
        const isNowChecked = item.classList.contains(this.CSS.itemChecked);
        checkbox.innerHTML = this._getCheckboxIcon(isNowChecked);
        
        // Dispatch input event to trigger autosave natively in Editor.js
        this.nodes.wrapper.dispatchEvent(new CustomEvent('input', { bubbles: true }));
      });
    }
    
    // Text UI element
    const textDiv = document.createElement('div');
    textDiv.classList.add(this.CSS.text);
    textDiv.contentEditable = !this.readOnly;
    textDiv.innerHTML = text;
    
    item.appendChild(checkbox);
    item.appendChild(textDiv);
    return item;
  }

  _getCheckboxIcon(checked) {
      return checked 
        ? '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>'
        : '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>';
  }

  _handleEnter(e, item) {
    e.preventDefault();
    const contentDiv = item.querySelector(`.${this.CSS.text}`);
    
    if (contentDiv.textContent.trim() === '') {
      // We are at root. Exit to new block.
      const currentIndex = this.api.blocks.getCurrentBlockIndex();
      item.remove();
      this.api.blocks.insert();
      this.api.caret.setToBlock(currentIndex + 1, 'start');
    } else {
      // Split text on Enter
      const selection = window.getSelection();
      let afterCursorHtml = '';
      if (selection.rangeCount > 0) {
        const range = selection.getRangeAt(0);
        const postCursorRange = range.cloneRange();
        postCursorRange.selectNodeContents(contentDiv);
        postCursorRange.setStart(range.endContainer, range.endOffset);
        const fragment = postCursorRange.extractContents();
        const tempDiv = document.createElement('div');
        tempDiv.appendChild(fragment);
        afterCursorHtml = tempDiv.innerHTML;
      }
      
      const newItem = this._createItem(afterCursorHtml, false);
      item.parentNode.insertBefore(newItem, item.nextSibling);
      this._focus(newItem);
    }
  }

  _handleBackspace(e, item) {
    const contentDiv = item.querySelector(`.${this.CSS.text}`);
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    
    const range = selection.getRangeAt(0);
    let isAtStart = false;
    if (range.collapsed) {
       const preCaretRange = range.cloneRange();
       preCaretRange.selectNodeContents(contentDiv);
       preCaretRange.setEnd(range.startContainer, range.startOffset);
       if (preCaretRange.toString().length === 0) {
           isAtStart = true;
       }
    }
    
    if (isAtStart) {
      const prev = item.previousElementSibling;
      if (contentDiv.textContent.trim() === '') {
        e.preventDefault();
        if (prev) {
          item.remove();
          this._focus(prev, true);
        } else {
          if (this.nodes.wrapper.children.length === 1) {
            const currentIndex = this.api.blocks.getCurrentBlockIndex();
            this.api.blocks.delete(currentIndex);
            if (currentIndex > 0) {
              this.api.caret.setToBlock(currentIndex - 1, 'end');
            }
          }
        }
      } else if (prev) {
        // Merge with previous item
        e.preventDefault();
        const prevContent = prev.querySelector(`.${this.CSS.text}`);
        const originalLength = prevContent.childNodes.length;
        
        while (contentDiv.firstChild) {
          prevContent.appendChild(contentDiv.firstChild);
        }
        
        item.remove();
        
        const newRange = document.createRange();
        if (originalLength > 0 && prevContent.childNodes.length >= originalLength) {
            newRange.setStartBefore(prevContent.childNodes[originalLength]);
        } else {
            newRange.selectNodeContents(prevContent);
            newRange.collapse(false);
        }
        newRange.collapse(true);
        selection.removeAllRanges();
        selection.addRange(newRange);
      }
    }
  }

  _focus(item, atEnd = false) {
    const contentDiv = item.querySelector(`.${this.CSS.text}`);
    contentDiv.focus();
    if (atEnd) {
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(contentDiv);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    }
  }

  save(blockContent) {
    const items = [];
    const children = Array.from(blockContent.children);
    
    children.forEach(item => {
      const contentDiv = item.querySelector(`.${this.CSS.text}`);
      if (contentDiv) {
        const text = contentDiv.innerHTML;
        const checked = item.classList.contains(this.CSS.itemChecked);
        
        if (text.trim() !== '') {
          items.push({
            text: text,
            checked: checked
          });
        }
      }
    });
    
    return {
      items: items
    };
  }
}

window.ChecklistBlock = ChecklistBlock;
