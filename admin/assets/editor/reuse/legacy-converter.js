/**
 * Task T7: Non-destructive Legacy Content Converter (Skeleton).
 */
(function (global) {
  'use strict';

  class LegacyConverter {
    constructor() {}

    previewConversion(rawHtml) {
      // Developer 7: Parse HTML headings, paragraphs, lists into canonical JSON blocks
      return {
        blocks: []
      };
    }
  }

  global.KcLegacyConverter = LegacyConverter;
})(typeof window !== 'undefined' ? window : this);
