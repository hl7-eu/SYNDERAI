<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xd="http://www.oxygenxml.com/ns/doc/xsl" xmlns:fn="http://www.w3.org/2005/xpath-functions" xmlns:f="http://hl7.org/fhir" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" exclude-result-prefixes="#all" version="3.0">
    
    <xsl:include href="fhir-json2xml.xsl"/>
    
    <xsl:param name="JSONfile" as="xs:string"/>
    <xsl:param name="json-string" as="xs:string" select="unparsed-text($JSONfile)"/>
    
    <xsl:template name="xsl:initial-template">
        <xsl:apply-templates select="json-to-xml($json-string)/*"/>
    </xsl:template>
    
</xsl:stylesheet>
