/**
 * PM Edit Plugin JavaScript
 */

function pmEditExpandDiff(element) {
    var isExpanded = element.getAttribute('data-expanded') === '1';
    var nextContent = element.nextElementSibling;
    
    if (isExpanded) {
        // Collapse
        element.setAttribute('data-expanded', '0');
        element.style.display = 'inline';
        if (nextContent) {
            nextContent.style.display = 'none';
        }
    } else {
        // Expand
        element.setAttribute('data-expanded', '1');
        element.style.display = 'none';
        if (nextContent) {
            nextContent.style.display = 'inline';
        }
    }
}

function pmEditCollapseDiff(element) {
    var isExpanded = element.getAttribute('data-expanded') === '1';
    var prevContent = element.previousElementSibling;
    
    if (isExpanded) {
        element.setAttribute('data-expanded', '0');
        element.style.display = 'none';
        if (prevContent) {
            prevContent.style.display = 'inline';
        }
    }
}
