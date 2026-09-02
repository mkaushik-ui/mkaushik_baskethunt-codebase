/**
 * Task A2-T03: Nested Bulleted List Block
 * Custom professional implementation for SOI Knowledge Center
 */
class ListBlock {
  static get toolbox() {
    return {
      title: 'List',
      icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>'
    };
  }

  static get isReadOnlySupported() {
    return true;
  }

  static get sanitize() {
    return {
      content: {
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
      style: data.style || 'unordered',
      items: data.items || []
    };
    
    // CSS classes for styling matching kc-editor.css tokens
    this.CSS = {
      baseBlock: this.api.styles.block,
      wrapper: 'kc-tool-list',
      list: 'kc-list-tool-ul',
      item: 'kc-list-tool-li'
    };
    
    this.nodes = {
      wrapper: null,
      list: null
    };
    
    this.settings = [
      {
        name: 'unordered',
        icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>'
      },
      {
        name: 'ordered',
        icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><line x1="10" y1="6" x2="21" y2="6"></line><line x1="10" y1="12" x2="21" y2="12"></line><line x1="10" y1="18" x2="21" y2="18"></line><path d="M4 6h1v4"></path><path d="M4 10h2"></path><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"></path></svg>'
      }
    ];
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
    
    const listTag = this.data.style === 'ordered' ? 'ol' : 'ul';
    this.nodes.list = document.createElement(listTag);
    this.nodes.list.classList.add(this.CSS.list);
    
    if (this.data.items.length === 0) {
      this.nodes.list.appendChild(this._createItem());
    } else {
      this.nodes.list.appendChild(this._renderItems(this.data.items));
    }
    
    this.nodes.wrapper.appendChild(this.nodes.list);
    
    if (!this.readOnly) {
      this.nodes.wrapper.addEventListener('keydown', (e) => {
        const item = e.target.closest(`.${this.CSS.item}`);
        if (!item) return;

        if (e.key === 'Enter') {
          this._handleEnter(e, item);
        } else if (e.key === 'Backspace') {
          this._handleBackspace(e, item);
        } else if (e.key === 'Tab') {
          if (e.shiftKey) {
            this._handleShiftTab(e, item);
          } else {
            this._handleTab(e, item);
          }
        }
      });
    }

    return this.nodes.wrapper;
  }
  
  _renderItems(items) {
    const fragment = document.createDocumentFragment();
    items.forEach(itemData => {
      const item = this._createItem(itemData.content);
      if (itemData.items && itemData.items.length > 0) {
        // Inherit the same style for nested lists
        const listTag = this.data.style === 'ordered' ? 'ol' : 'ul';
        const subList = document.createElement(listTag);
        subList.classList.add(this.CSS.list);
        subList.appendChild(this._renderItems(itemData.items));
        item.appendChild(subList);
      }
      fragment.appendChild(item);
    });
    return fragment;
  }

  _createItem(content = '') {
    const item = document.createElement('li');
    item.classList.add(this.CSS.item);
    
    const div = document.createElement('div');
    div.contentEditable = !this.readOnly;
    div.innerHTML = content;
    
    item.appendChild(div);
    return item;
  }

  _handleEnter(e, item) {
    e.preventDefault();
    const contentDiv = item.firstElementChild;
    
    if (contentDiv.textContent.trim() === '' && !item.querySelector('ul') && !item.querySelector('ol')) {
      // Empty item: unindent or exit block
      if (item.parentNode !== this.nodes.list) {
        this._handleShiftTab(e, item);
      } else {
        // We are at root. Exit to new block.
        const currentIndex = this.api.blocks.getCurrentBlockIndex();
        item.remove();
        this.api.blocks.insert();
        this.api.caret.setToBlock(currentIndex + 1, 'start');
      }
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
      
      const newItem = this._createItem(afterCursorHtml);
      item.parentNode.insertBefore(newItem, item.nextSibling);
      this._focus(newItem);
    }
  }

  _handleBackspace(e, item) {
    const contentDiv = item.firstElementChild;
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
      if (contentDiv.textContent.trim() === '' && !item.querySelector('ul') && !item.querySelector('ol')) {
        e.preventDefault();
        if (prev) {
          item.remove();
          this._focus(prev, true);
        } else {
          if (item.parentNode !== this.nodes.list) {
            this._handleShiftTab(e, item);
          } else if (this.nodes.list.children.length === 1) {
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
        const prevContent = prev.firstElementChild;
        const originalLength = prevContent.childNodes.length;
        
        while (contentDiv.firstChild) {
          prevContent.appendChild(contentDiv.firstChild);
        }
        
        const subList = item.querySelector('ul') || item.querySelector('ol');
        if (subList) {
          let prevSubList = prev.querySelector('ul') || prev.querySelector('ol');
          if (!prevSubList) {
            prev.appendChild(subList);
          } else {
            while(subList.firstChild) {
              prevSubList.appendChild(subList.firstChild);
            }
          }
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
      } else if (item.parentNode !== this.nodes.list) {
        // Unindent if at beginning of sublist
        e.preventDefault();
        this._handleShiftTab(e, item);
      }
    }
  }

  _handleTab(e, item) {
    e.preventDefault();
    const prev = item.previousElementSibling;
    if (prev) {
      let subList = prev.querySelector('ul') || prev.querySelector('ol');
      if (!subList) {
        const listTag = this.data.style === 'ordered' ? 'ol' : 'ul';
        subList = document.createElement(listTag);
        subList.classList.add(this.CSS.list);
        prev.appendChild(subList);
      }
      subList.appendChild(item);
      this._focus(item);
    }
  }

  _handleShiftTab(e, item) {
    e.preventDefault();
    const currentList = item.parentNode;
    if (currentList !== this.nodes.list) {
      const parentItem = currentList.parentNode;
      parentItem.parentNode.insertBefore(item, parentItem.nextSibling);
      
      if (currentList.children.length === 0) {
        currentList.remove();
      }
      this._focus(item);
    }
  }

  _focus(item, atEnd = false) {
    const contentDiv = item.firstElementChild;
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
    return {
      style: this.data.style,
      items: this._extractItems(this.nodes.list)
    };
  }
  
  _extractItems(listNode) {
    const items = [];
    const children = Array.from(listNode.children).filter(child => child.tagName === 'LI');
    
    children.forEach(li => {
      const contentDiv = li.querySelector('div[contenteditable]');
      const content = contentDiv ? contentDiv.innerHTML : '';
      
      const subList = li.querySelector('ul') || li.querySelector('ol');
      const subItems = subList ? this._extractItems(subList) : [];
      
      if (content.trim() !== '' || subItems.length > 0) {
        items.push({
          content: content,
          items: subItems
        });
      }
    });
    
    return items;
  }

  renderSettings() {
    const wrapper = document.createElement('div');
    
    this.settings.forEach(tune => {
      const button = document.createElement('div');
      
      // Editor.js default classes for settings menu
      button.classList.add(this.api.styles.settingsButton);
      if (this.data.style === tune.name) {
        button.classList.add(this.api.styles.settingsButtonActive);
      }
      
      button.innerHTML = tune.icon;
      button.addEventListener('click', () => {
        this._toggleListStyle(tune.name);
        // Refresh active state in menu
        Array.from(wrapper.children).forEach(btn => btn.classList.remove(this.api.styles.settingsButtonActive));
        button.classList.add(this.api.styles.settingsButtonActive);
      });
      
      wrapper.appendChild(button);
    });
    
    return wrapper;
  }
  
  _toggleListStyle(style) {
    if (this.data.style === style) return;
    this.data.style = style;
    
    const newListTag = style === 'ordered' ? 'ol' : 'ul';
    const swapList = (oldList) => {
      const newList = document.createElement(newListTag);
      newList.classList.add(this.CSS.list);
      while (oldList.firstChild) {
        newList.appendChild(oldList.firstChild);
      }
      return newList;
    };

    // Swap root list
    const newRootList = swapList(this.nodes.list);
    this.nodes.wrapper.replaceChild(newRootList, this.nodes.list);
    this.nodes.list = newRootList;

    // Swap all nested lists
    const nestedLists = this.nodes.list.querySelectorAll('ul, ol');
    nestedLists.forEach(nested => {
      const parent = nested.parentNode;
      const swapped = swapList(nested);
      parent.replaceChild(swapped, nested);
    });
  }
}

window.ListBlock = ListBlock; // Expose globally if required
