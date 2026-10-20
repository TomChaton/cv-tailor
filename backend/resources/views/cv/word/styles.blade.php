{{-- The styles the document's paragraphs name.

     **These are the word processor's own built-in styles**, declared here
     under the ids and names it knows them by, so a heading in this document is
     the same object as a heading the owner applies by hand: it appears in the
     navigation pane, it can be restyled once for every section at a time, and
     it survives the editing this file exists for.

     The formatting on them is deliberately thin. The PDF is what gets sent and
     this is what gets edited, so chasing the PDF's typography here would fight
     every change the owner makes. --}}
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:qFormat/>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Title">
        <w:name w:val="Title"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:uiPriority w:val="10"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:spacing w:after="60"/>
        </w:pPr>
        <w:rPr>
            <w:b/>
            <w:sz w:val="40"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Subtitle">
        <w:name w:val="Subtitle"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:uiPriority w:val="11"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:spacing w:after="60"/>
        </w:pPr>
        <w:rPr>
            <w:i/>
            <w:sz w:val="26"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:uiPriority w:val="9"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:outlineLvl w:val="0"/>
            <w:spacing w:before="320" w:after="120"/>
        </w:pPr>
        <w:rPr>
            <w:b/>
            <w:caps/>
            <w:sz w:val="24"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:uiPriority w:val="9"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:outlineLvl w:val="1"/>
            <w:spacing w:before="200" w:after="0"/>
        </w:pPr>
        <w:rPr>
            <w:b/>
            <w:sz w:val="24"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="ListParagraph">
        <w:name w:val="List Paragraph"/>
        <w:basedOn w:val="Normal"/>
        <w:uiPriority w:val="34"/>
        <w:qFormat/>
        <w:pPr>
            <w:contextualSpacing/>
        </w:pPr>
    </w:style>
</w:styles>
